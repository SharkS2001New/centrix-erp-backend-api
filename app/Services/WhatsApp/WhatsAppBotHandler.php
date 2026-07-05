<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessageLog;
use App\Services\Customers\CustomerPhoneLookup;
use App\Services\Inventory\StockUomDisplayService;
use App\Support\PhoneNumber;
use InvalidArgumentException;

class WhatsAppBotHandler
{
    public const STATE_MAIN_MENU = 'main_menu';

    public const STATE_BROWSE = 'browse';

    public const STATE_ENTER_QTY = 'enter_qty';

    public const STATE_CART = 'cart';

    public const STATE_REVIEW = 'review';

    public const STATE_REPEAT_CONFIRM = 'repeat_confirm';

    public const STATE_TRACK = 'track';

    public const STATE_UNKNOWN = 'unknown';

    public function __construct(
        protected CustomerPhoneLookup $customerLookup,
        protected WhatsAppOrderService $orders,
        protected StockUomDisplayService $stockUom,
        protected MetaWhatsAppClient $whatsapp,
        protected WhatsAppConfigResolver $configResolver,
    ) {}

    public function handleInbound(
        ResolvedWhatsAppConfig $config,
        string $fromPhone,
        string $text,
        ?string $providerMessageId,
    ): void {
        if ($providerMessageId) {
            $exists = WhatsappMessageLog::query()
                ->where('organization_id', $config->organizationId)
                ->where('provider_message_id', $providerMessageId)
                ->exists();
            if ($exists) {
                return;
            }
        }

        $botUser = $this->configResolver->botUser($config);
        if (! $botUser) {
            return;
        }

        $normalizedPhone = PhoneNumber::normalize($fromPhone) ?? $fromPhone;
        $conversation = $this->loadConversation($config, $normalizedPhone);
        $customer = $this->resolveCustomer($config, $conversation, $normalizedPhone);

        $reply = $this->dispatch(
            $config,
            $botUser,
            $conversation,
            $customer,
            $this->normalizeInput($text),
        );

        $this->persistConversation($conversation, $customer);
        $this->logMessage($config, $conversation, 'in', $normalizedPhone, $text, $providerMessageId);
        $this->whatsapp->sendText($config, PhoneNumber::toE164($fromPhone) ?? $fromPhone, $reply);
        $this->logMessage($config, $conversation, 'out', null, $reply, null);
    }

    protected function dispatch(
        ResolvedWhatsAppConfig $config,
        User $botUser,
        WhatsappConversation $conversation,
        ?Customer $customer,
        string $input,
    ): string {
        if ($this->isGlobalCommand($input, ['MENU', 'HI', 'HELLO', 'START'])) {
            $conversation->state = $customer ? self::STATE_MAIN_MENU : self::STATE_UNKNOWN;

            return $customer
                ? $this->mainMenuMessage($customer)
                : $this->unknownCustomerMessage();
        }

        if ($input === 'CANCEL') {
            $conversation->payload = [];
            $conversation->state = $customer ? self::STATE_MAIN_MENU : self::STATE_UNKNOWN;

            return $customer
                ? "Cart cleared.\n\n".$this->mainMenuMessage($customer)
                : $this->unknownCustomerMessage();
        }

        if (! $customer) {
            $conversation->state = self::STATE_UNKNOWN;

            return $this->unknownCustomerMessage();
        }

        return match ($conversation->state) {
            self::STATE_MAIN_MENU => $this->handleMainMenu($conversation, $customer, $input, $botUser),
            self::STATE_BROWSE => $this->handleBrowse($conversation, $customer, $input),
            self::STATE_ENTER_QTY => $this->handleEnterQty($conversation, $customer, $input),
            self::STATE_CART => $this->handleCart($conversation, $customer, $input),
            self::STATE_REVIEW => $this->handleReview($conversation, $customer, $input, $botUser),
            self::STATE_REPEAT_CONFIRM => $this->handleRepeatConfirm($conversation, $customer, $input, $botUser),
            self::STATE_TRACK => $this->handleTrack($conversation, $customer, $input),
            default => $this->handleMainMenu($conversation, $customer, $input, $botUser),
        };
    }

    protected function handleMainMenu(
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
        User $botUser,
    ): string {
        return match ($input) {
            '1' => $this->startBrowse($conversation, $customer),
            '2' => $this->startRepeatLastOrder($conversation, $customer),
            '3' => $this->startTrackOrders($conversation, $customer),
            '4', 'HUMAN', 'HELP' => $this->handoffMessage(),
            default => $this->mainMenuMessage($customer),
        };
    }

    protected function handleBrowse(
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
    ): string {
        if ($input === '0') {
            $cart = $this->cartLines($conversation);
            if ($cart === []) {
                return $this->startBrowse($conversation, $customer);
            }
            $conversation->state = self::STATE_CART;

            return $this->cartMessage($conversation);
        }

        $products = collect($conversation->payload['browse_products'] ?? []);
        $index = (int) $input;
        if ($index < 1 || $index > $products->count()) {
            return "Please reply with a product number from the list.\n\n".$this->browseMessage($conversation, $customer);
        }

        $product = $products[$index - 1];
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'pending_product_code' => $product['product_code'],
            'pending_product_name' => $product['product_name'],
            'pending_uom' => $product['uom_snapshot'] ?? null,
        ]);
        $conversation->state = self::STATE_ENTER_QTY;

        $uom = is_array($product['uom_snapshot'] ?? null)
            ? (object) $product['uom_snapshot']
            : null;
        $label = $uom
            ? ($this->stockUom->formatMixedStockDisplay(1, $this->uomFromSnapshot($uom))['parts'][0]['label'] ?? 'units')
            : 'units';

        return "How many *{$label}* of *{$product['product_name']}*?\n\nReply with a number, or CANCEL to go back.";
    }

    protected function handleEnterQty(
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
    ): string {
        if (! is_numeric($input)) {
            return "Please reply with a number only (e.g. 10), or CANCEL.";
        }

        $displayQty = (float) $input;
        if ($displayQty <= 0) {
            return 'Quantity must be at least 1.';
        }

        $code = (string) ($conversation->payload['pending_product_code'] ?? '');
        $name = (string) ($conversation->payload['pending_product_name'] ?? $code);
        $uom = $this->uomFromSnapshot($conversation->payload['pending_uom'] ?? null);
        $factor = max(1.0, (float) ($uom?->conversion_factor ?? 1));
        $usesSmall = ($uom?->uses_small_packaging ?? true) !== false;
        $baseQty = $usesSmall && $factor > 1 ? $displayQty * $factor : $displayQty;

        $cart = $this->cartLines($conversation);
        $cart[] = [
            'product_code' => $code,
            'product_name' => $name,
            'quantity' => $baseQty,
            'display' => $this->stockUom->formatMixedStockDisplay($baseQty, $uom)['text'],
            'line_total' => null,
        ];
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'cart' => $cart,
            'pending_product_code' => null,
            'pending_product_name' => null,
        ]);
        $conversation->state = self::STATE_CART;

        return "Added ✅\n*{$name}* — ".$this->stockUom->formatMixedStockDisplay($baseQty, $uom)['text']."\n\n".$this->cartMessage($conversation);
    }

    protected function handleCart(
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
    ): string {
        return match ($input) {
            '1' => $this->startBrowse($conversation, $customer),
            '2' => $this->startReview($conversation, $customer),
            '3' => $this->clearCart($conversation, $customer),
            default => $this->cartMessage($conversation),
        };
    }

    protected function handleReview(
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
        User $botUser,
    ): string {
        if (str_starts_with(strtolower($input), 'NOTES:')) {
            $notes = trim(substr($input, 6));
            $conversation->payload = array_merge($conversation->payload ?? [], [
                'notes' => $notes,
            ]);

            return "Noted ✅\n\n".$this->reviewMessage($conversation, $customer);
        }

        if ($input === 'EDIT') {
            $conversation->state = self::STATE_CART;

            return $this->cartMessage($conversation);
        }

        if ($input !== 'CONFIRM') {
            return $this->reviewMessage($conversation, $customer);
        }

        try {
            $lines = array_map(
                fn (array $line) => [
                    'product_code' => $line['product_code'],
                    'quantity' => (float) $line['quantity'],
                ],
                $this->cartLines($conversation),
            );
            $result = $this->orders->placeOrder(
                $botUser,
                $customer,
                $lines,
                $conversation->payload['notes'] ?? null,
            );
            $conversation->payload = [];
            $conversation->state = self::STATE_MAIN_MENU;

            return "✅ *Order placed successfully*\n\n"
                ."Order #*{$result['order_num']}*\n"
                .'Total: *'.$this->orders->formatMoney((float) ($result['order_total'] ?? 0))."*\n"
                .'Status: '.ucfirst(str_replace('_', ' ', (string) ($result['status'] ?? 'received')))."\n\n"
                .$this->mainMenuMessage($customer);
        } catch (InvalidArgumentException $e) {
            return "Could not place order: {$e->getMessage()}\n\n".$this->reviewMessage($conversation, $customer);
        } catch (\Throwable $e) {
            report($e);

            return "Something went wrong placing your order. Please try again or reply *4* to talk to our team.\n\n"
                .$this->reviewMessage($conversation, $customer);
        }
    }

    protected function handleRepeatConfirm(
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
        User $botUser,
    ): string {
        if ($input === '3' || $input === 'CANCEL') {
            $conversation->state = self::STATE_MAIN_MENU;

            return $this->mainMenuMessage($customer);
        }

        if ($input === '2') {
            $conversation->state = self::STATE_CART;

            return $this->cartMessage($conversation);
        }

        if ($input !== '1' && $input !== 'CONFIRM') {
            return (string) ($conversation->payload['repeat_prompt'] ?? $this->mainMenuMessage($customer));
        }

        try {
            $lines = array_map(
                fn (array $line) => [
                    'product_code' => $line['product_code'],
                    'quantity' => (float) $line['quantity'],
                ],
                $this->cartLines($conversation),
            );
            $result = $this->orders->placeOrder($botUser, $customer, $lines);
            $conversation->payload = [];
            $conversation->state = self::STATE_MAIN_MENU;

            return "✅ *Order placed successfully*\n\n"
                ."Order #*{$result['order_num']}*\n"
                .'Total: *'.$this->orders->formatMoney((float) ($result['order_total'] ?? 0))."*\n\n"
                .$this->mainMenuMessage($customer);
        } catch (\Throwable $e) {
            report($e);

            return 'Could not repeat your order. Reply *4* for help, or *MENU* to start over.';
        }
    }

    protected function handleTrack(
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
    ): string {
        if ($input === '0' || $this->isGlobalCommand($input, ['MENU'])) {
            $conversation->state = self::STATE_MAIN_MENU;

            return $this->mainMenuMessage($customer);
        }

        return $this->trackOrdersMessage($customer);
    }

    protected function startBrowse(WhatsappConversation $conversation, Customer $customer): string
    {
        $products = $this->orders->quickListProducts($customer)->values()->all();
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'browse_products' => array_map(function (array $row) {
                $uom = $row['uom'];

                return [
                    'product_code' => $row['product_code'],
                    'product_name' => $row['product_name'],
                    'unit_price' => $row['unit_price'],
                    'uom_snapshot' => $uom ? $uom->only([
                        'conversion_factor',
                        'full_name',
                        'small_packaging_label',
                        'middle_packaging_label',
                        'middle_factor',
                        'uses_small_packaging',
                        'uom_type',
                    ]) : null,
                ];
            }, $products),
        ]);
        $conversation->state = self::STATE_BROWSE;

        if ($products === []) {
            $conversation->state = self::STATE_MAIN_MENU;

            return "No previous orders found to suggest products.\n\n".$this->mainMenuMessage($customer);
        }

        return $this->browseMessage($conversation, $customer);
    }

    protected function startRepeatLastOrder(WhatsappConversation $conversation, Customer $customer): string
    {
        $sale = $this->orders->lastSaleForCustomer($customer);
        if (! $sale) {
            return "No previous order found.\n\n".$this->mainMenuMessage($customer);
        }

        $lines = $this->orders->summarizeSaleLines($sale);
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'cart' => $lines,
            'repeat_sale_id' => $sale->id,
        ]);
        $conversation->state = self::STATE_REPEAT_CONFIRM;

        $body = "Your last order (#{$sale->order_num}):\n\n";
        foreach ($lines as $line) {
            $body .= "• {$line['display']} {$line['product_name']} — ".$this->orders->formatMoney($line['line_total'])."\n";
        }
        $body .= "\nTotal: *".$this->orders->formatMoney((float) $sale->order_total)."*\n\n";
        $body .= "1️⃣ Yes, place same order\n2️⃣ Edit first\n3️⃣ Cancel";
        $conversation->payload['repeat_prompt'] = $body;

        return $body;
    }

    protected function startReview(WhatsappConversation $conversation, Customer $customer): string
    {
        if ($this->cartLines($conversation) === []) {
            return "Your cart is empty. Reply *1* to add items.\n\n".$this->mainMenuMessage($customer);
        }
        $conversation->state = self::STATE_REVIEW;

        return $this->reviewMessage($conversation, $customer);
    }

    protected function startTrackOrders(WhatsappConversation $conversation, Customer $customer): string
    {
        $conversation->state = self::STATE_TRACK;

        return $this->trackOrdersMessage($customer);
    }

    protected function clearCart(WhatsappConversation $conversation, Customer $customer): string
    {
        $conversation->payload = array_merge($conversation->payload ?? [], ['cart' => []]);
        $conversation->state = self::STATE_MAIN_MENU;

        return "Cart cleared.\n\n".$this->mainMenuMessage($customer);
    }

    protected function mainMenuMessage(Customer $customer): string
    {
        $route = $customer->route?->route_name ?? '—';
        $credit = $this->orders->creditAvailable($customer);
        $creditLine = $credit !== null
            ? 'Credit available: *'.$this->orders->formatMoney($credit)."*\n"
            : '';

        return "Hello *{$customer->customer_name}* 👋\n"
            ."Route: *{$route}*\n"
            .$creditLine
            ."\n1️⃣ Place new order\n"
            ."2️⃣ Repeat last order\n"
            ."3️⃣ Track my orders\n"
            ."4️⃣ Talk to someone\n\n"
            .'Reply with a number.';
    }

    protected function browseMessage(WhatsappConversation $conversation, Customer $customer): string
    {
        $products = collect($conversation->payload['browse_products'] ?? []);
        if ($products->isEmpty()) {
            return $this->startBrowse($conversation, $customer);
        }

        $lines = ["Choose a product:\n"];
        foreach ($products->values() as $index => $product) {
            $price = $this->orders->formatMoney((float) $product['unit_price']);
            $lines[] = ($index + 1).". {$product['product_name']} — {$price}";
        }
        $cartCount = count($this->cartLines($conversation));
        $lines[] = "\n0️⃣ ".($cartCount > 0 ? "Review cart ({$cartCount} items)" : 'Back to menu');
        $lines[] = "\nReply with item number.";

        return implode("\n", $lines);
    }

    protected function cartMessage(WhatsappConversation $conversation): string
    {
        $cart = $this->cartLines($conversation);
        if ($cart === []) {
            return "Your cart is empty.\n\n1️⃣ Add items\n2️⃣ Review order\n3️⃣ Clear cart";
        }

        $lines = ["🛒 *Your cart*\n"];
        foreach ($cart as $item) {
            $lines[] = '• '.($item['display'] ?? '').' '.$item['product_name'];
        }
        $lines[] = "\n1️⃣ Add another item";
        $lines[] = '2️⃣ Review & place order';
        $lines[] = '3️⃣ Clear cart';

        return implode("\n", $lines);
    }

    protected function reviewMessage(WhatsappConversation $conversation, Customer $customer): string
    {
        $cart = $this->cartLines($conversation);
        $lines = ["📋 *Order summary*\n", "Customer: *{$customer->customer_name}*\n"];
        foreach ($cart as $item) {
            $lines[] = '• '.($item['display'] ?? '').' '.$item['product_name'];
        }
        $notes = trim((string) ($conversation->payload['notes'] ?? ''));
        if ($notes !== '') {
            $lines[] = "\nNotes: {$notes}";
        }
        $lines[] = "\nReply *CONFIRM* to place order";
        $lines[] = 'Reply *EDIT* to change cart';
        $lines[] = 'Reply *notes:* your delivery note (optional)';

        return implode("\n", $lines);
    }

    protected function trackOrdersMessage(Customer $customer): string
    {
        $orders = $this->orders->recentOrders($customer);
        if ($orders === []) {
            return "No orders found.\n\n0️⃣ Back to menu";
        }

        $lines = ["*Recent orders*\n"];
        foreach ($orders as $order) {
            $lines[] = "#{$order['order_num']} — ".ucfirst($order['status'])
                .' — '.$this->orders->formatMoney($order['total']);
        }
        $lines[] = "\n0️⃣ Back to menu";

        return implode("\n", $lines);
    }

    protected function unknownCustomerMessage(): string
    {
        return "We don't recognize this WhatsApp number.\n\n"
            ."Please contact our sales team to register your shop, or call your account manager.\n\n"
            .'Reply *MENU* anytime once your number is linked.';
    }

    protected function handoffMessage(): string
    {
        return "A team member will assist you during business hours.\n\n"
            .'For urgent orders, please call your sales rep.\n\n'
            .'Reply *MENU* to return to ordering.';
    }

    /** @return list<array<string, mixed>> */
    protected function cartLines(WhatsappConversation $conversation): array
    {
        $cart = $conversation->payload['cart'] ?? [];

        return is_array($cart) ? $cart : [];
    }

    protected function loadConversation(ResolvedWhatsAppConfig $config, string $phone): WhatsappConversation
    {
        return WhatsappConversation::query()->firstOrCreate(
            [
                'organization_id' => $config->organizationId,
                'phone' => $phone,
            ],
            [
                'state' => self::STATE_MAIN_MENU,
                'payload' => [],
                'last_message_at' => now(),
                'expires_at' => now()->addHours((int) config('whatsapp.conversation_ttl_hours', 24)),
            ],
        );
    }

    protected function resolveCustomer(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        string $phone,
    ): ?Customer {
        if ($conversation->customer_num) {
            $existing = Customer::query()
                ->where('organization_id', $config->organizationId)
                ->where('customer_num', $conversation->customer_num)
                ->whereNull('deleted_at')
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $customer = $this->customerLookup->findByPhone($config->organizationId, $phone);
        if ($customer) {
            $conversation->customer_num = $customer->customer_num;
        }

        return $customer;
    }

    protected function persistConversation(WhatsappConversation $conversation, ?Customer $customer): void
    {
        $conversation->last_message_at = now();
        $conversation->expires_at = now()->addHours((int) config('whatsapp.conversation_ttl_hours', 24));
        if ($customer && ! $conversation->customer_num) {
            $conversation->customer_num = $customer->customer_num;
        }
        $conversation->save();
    }

    protected function logMessage(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        string $direction,
        ?string $fromPhone,
        ?string $body,
        ?string $providerMessageId,
    ): void {
        if ($direction === 'in' && $providerMessageId) {
            try {
                WhatsappMessageLog::query()->create([
                    'organization_id' => $config->organizationId,
                    'conversation_id' => $conversation->id,
                    'provider_message_id' => $providerMessageId,
                    'direction' => $direction,
                    'from_phone' => $fromPhone,
                    'body' => $body,
                    'created_at' => now(),
                ]);
            } catch (\Throwable) {
                // duplicate provider id — ignore
            }

            return;
        }

        WhatsappMessageLog::query()->create([
            'organization_id' => $config->organizationId,
            'conversation_id' => $conversation->id,
            'provider_message_id' => null,
            'direction' => $direction,
            'from_phone' => $fromPhone,
            'body' => $body,
            'created_at' => now(),
        ]);
    }

    protected function normalizeInput(string $text): string
    {
        $trimmed = trim($text);
        if (str_starts_with(strtolower($trimmed), 'notes:')) {
            return 'NOTES:'.trim(substr($trimmed, 6));
        }

        return strtoupper($trimmed);
    }

    /** @param  list<string>  $commands */
    protected function isGlobalCommand(string $input, array $commands): bool
    {
        return in_array($input, $commands, true);
    }

    protected function uomFromSnapshot(mixed $snapshot): ?object
    {
        if (! is_array($snapshot) || $snapshot === []) {
            return null;
        }

        return (object) $snapshot;
    }
}
