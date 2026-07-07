<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessageLog;
use App\Services\Customers\CustomerPhoneLookup;
use App\Services\Erp\CapabilityGate;
use App\Services\Inventory\StockUomDisplayService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
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

    public const STATE_HANDOFF = 'handoff';

    public const STATE_SEARCH_RESULTS = 'search_results';

    public const STATE_UNKNOWN = 'unknown';

    public function __construct(
        protected CustomerPhoneLookup $customerLookup,
        protected WhatsAppOrderService $orders,
        protected WhatsAppProductCatalogService $catalog,
        protected WhatsAppHandoffService $handoffs,
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
            Log::warning('whatsapp.missing_bot_user', [
                'organization_id' => $config->organizationId,
            ]);
            $this->whatsapp->sendText(
                $config,
                PhoneNumber::toE164($fromPhone) ?? $fromPhone,
                'Ordering is temporarily unavailable. Please contact the office for help.',
            );

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
            $text,
        );

        $this->persistConversation($conversation, $customer);
        $this->logMessage($config, $conversation, 'in', $normalizedPhone, $text, $providerMessageId);
        $sent = $this->whatsapp->sendText($config, PhoneNumber::toE164($fromPhone) ?? $fromPhone, $reply);
        if ($sent) {
            $this->logMessage($config, $conversation, 'out', null, $reply, null);
        } else {
            $this->logMessage(
                $config,
                $conversation,
                'system',
                $normalizedPhone,
                'Outbound WhatsApp reply failed.',
                null,
                ['event' => 'send_failed'],
            );
        }
    }

    protected function dispatch(
        ResolvedWhatsAppConfig $config,
        User $botUser,
        WhatsappConversation $conversation,
        ?Customer $customer,
        string $input,
        string $rawText = '',
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
            self::STATE_MAIN_MENU => $this->handleMainMenu($config, $conversation, $customer, $input, $rawText, $botUser),
            self::STATE_BROWSE => $this->handleBrowse($config, $conversation, $customer, $input, $botUser),
            self::STATE_SEARCH_RESULTS => $this->handleSearchResults($config, $conversation, $customer, $input, $botUser),
            self::STATE_ENTER_QTY => $this->handleEnterQty($conversation, $customer, $input),
            self::STATE_CART => $this->handleCart($config, $conversation, $customer, $input, $botUser),
            self::STATE_REVIEW => $this->handleReview($config, $conversation, $customer, $input, $botUser),
            self::STATE_REPEAT_CONFIRM => $this->handleRepeatConfirm($config, $conversation, $customer, $input, $botUser),
            self::STATE_TRACK => $this->handleTrack($conversation, $customer, $input),
            self::STATE_HANDOFF => $this->handleHandoffState($config, $conversation, $customer, $input),
            default => $this->handleMainMenu($config, $conversation, $customer, $input, $rawText, $botUser),
        };
    }

    protected function handleMainMenu(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
        string $rawText,
        User $botUser,
    ): string {
        return match ($input) {
            '1' => $this->startBrowse($config, $conversation, $customer, $botUser),
            '2' => $this->startRepeatLastOrder($conversation, $customer),
            '3' => $this->startTrackOrders($conversation, $customer),
            '4', 'HUMAN', 'HELP' => $this->requestHandoff($config, $conversation, $customer, $botUser, $rawText),
            default => $this->tryHandleProductText($config, $conversation, $customer, $input, $botUser)
                ?? $this->mainMenuMessage($customer),
        };
    }

    protected function handleBrowse(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
        User $botUser,
    ): string {
        if ($input === 'NEXT' || $input === 'MORE') {
            $page = (int) ($conversation->payload['browse_page'] ?? 1) + 1;

            return $this->startBrowse($config, $conversation, $customer, $botUser, $page);
        }

        if ($input === '0') {
            $cart = $this->cartLines($conversation);
            if ($cart === []) {
                $conversation->state = self::STATE_MAIN_MENU;

                return $this->mainMenuMessage($customer);
            }
            $conversation->state = self::STATE_CART;

            return $this->cartMessage($conversation);
        }

        $products = collect($conversation->payload['browse_products'] ?? []);
        $index = (int) $input;
        if ($index >= 1 && $index <= $products->count()) {
            $product = $products[$index - 1];

            return $this->beginQuantityForProduct($conversation, $product);
        }

        $searchReply = $this->tryHandleProductText($config, $conversation, $customer, $input, $botUser);
        if ($searchReply !== null) {
            return $searchReply;
        }

        return "Reply with a product number, type a product name to search, or NEXT for more.\n\n"
            .$this->browseMessage($config, $conversation, $customer, $botUser);
    }

    protected function handleSearchResults(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
        User $botUser,
    ): string {
        if ($input === 'NEXT' || $input === 'MORE') {
            $page = (int) ($conversation->payload['search_page'] ?? 1) + 1;
            $term = (string) ($conversation->payload['search_term'] ?? '');

            return $this->showSearchResults($config, $conversation, $customer, $botUser, $term, $page);
        }

        if ($input === '0') {
            $conversation->state = self::STATE_MAIN_MENU;

            return $this->mainMenuMessage($customer);
        }

        $products = collect($conversation->payload['search_results'] ?? []);
        $index = (int) $input;
        if ($index >= 1 && $index <= $products->count()) {
            $product = $products[$index - 1];
            $presetQty = $conversation->payload['search_preset_qty'] ?? null;
            if ($presetQty !== null && (float) $presetQty > 0) {
                return $this->addProductToCart($conversation, $product, (float) $presetQty);
            }

            return $this->beginQuantityForProduct($conversation, $product);
        }

        $searchReply = $this->tryHandleProductText($config, $conversation, $customer, $input, $botUser);
        if ($searchReply !== null) {
            return $searchReply;
        }

        return "Pick a number from the list, type another name to search, or NEXT for more.\n\n"
            .$this->searchResultsMessage($conversation);
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
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
        User $botUser,
    ): string {
        if (preg_match('/^R(\d+)$/', $input, $matches)) {
            return $this->removeCartLine($conversation, $customer, (int) $matches[1]);
        }

        if (preg_match('/^E(\d+)\s+(\d+(?:\.\d+)?)$/', $input, $matches)) {
            return $this->editCartLine($conversation, $customer, (int) $matches[1], (float) $matches[2]);
        }

        if ($searchReply = $this->tryHandleProductText($config, $conversation, $customer, $input, $botUser)) {
            return $searchReply;
        }

        return match ($input) {
            '1' => $this->startBrowse($config, $conversation, $customer, $botUser),
            '2' => $this->startReview($config, $conversation, $customer, $botUser),
            '3' => $this->clearCart($conversation, $customer),
            default => $this->cartMessage($conversation),
        };
    }

    protected function handleReview(
        ResolvedWhatsAppConfig $config,
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

            return "Noted ✅\n\n".$this->reviewMessage($config, $conversation, $customer, $botUser);
        }

        if ($input === 'EDIT') {
            $conversation->state = self::STATE_CART;

            return $this->cartMessage($conversation);
        }

        if ($input !== 'CONFIRM') {
            return $this->reviewMessage($config, $conversation, $customer, $botUser);
        }

        $preview = $this->orders->previewCart(
            $customer,
            $botUser,
            $this->gateForConfig($config),
            $this->cartLines($conversation),
        );
        if ($preview['stock_warnings'] !== []) {
            return "⚠️ Stock issue:\n• ".implode("\n• ", $preview['stock_warnings'])
                ."\n\nAdjust your cart (reply *EDIT*) or confirm if your branch will fulfil partial stock.\n\n"
                .$this->reviewMessage($config, $conversation, $customer, $botUser);
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
            $conversation->payload = [
                'last_sale_id' => $result['sale_id'],
                'last_order_num' => $result['order_num'],
            ];
            $conversation->state = self::STATE_MAIN_MENU;

            return "✅ *Order placed successfully*\n\n"
                ."Order #*{$result['order_num']}*\n"
                .'Total: *'.$this->orders->formatMoney((float) ($result['order_total'] ?? 0))."*\n"
                .'Status: '.ucfirst(str_replace('_', ' ', (string) ($result['status'] ?? 'received')))."\n\n"
                .$this->mainMenuMessage($customer);
        } catch (InvalidArgumentException $e) {
            $this->orders->logOrderFailure(
                $config->organizationId,
                $conversation->id,
                $conversation->phone,
                $e->getMessage(),
                $this->cartLines($conversation),
            );

            return "Could not place order: {$e->getMessage()}\n\n".$this->reviewMessage($config, $conversation, $customer, $botUser);
        } catch (\Throwable $e) {
            report($e);
            $this->orders->logOrderFailure(
                $config->organizationId,
                $conversation->id,
                $conversation->phone,
                $e->getMessage(),
                $this->cartLines($conversation),
            );

            return "Something went wrong placing your order. Please try again or reply *4* to talk to our team.\n\n"
                .$this->reviewMessage($config, $conversation, $customer, $botUser);
        }
    }

    protected function handleRepeatConfirm(
        ResolvedWhatsAppConfig $config,
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
            $this->orders->logOrderFailure(
                $config->organizationId,
                $conversation->id,
                $conversation->phone,
                $e->getMessage(),
                $this->cartLines($conversation),
            );

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

    protected function startBrowse(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        User $botUser,
        int $page = 1,
    ): string {
        $gate = $this->gateForConfig($config);
        $result = $this->catalog->browseInStock($customer, $botUser, $gate, $page);
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'browse_products' => $result['items'],
            'browse_page' => $result['page'],
            'browse_has_more' => $result['has_more'],
        ]);
        $conversation->state = self::STATE_BROWSE;

        if ($result['items'] === []) {
            $conversation->state = self::STATE_MAIN_MENU;

            return "No in-stock products are available right now.\n\n".$this->mainMenuMessage($customer);
        }

        return $this->browseMessage($config, $conversation, $customer, $botUser);
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

    protected function startReview(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        User $botUser,
    ): string {
        if ($this->cartLines($conversation) === []) {
            return "Your cart is empty. Reply *1* to add items.\n\n".$this->mainMenuMessage($customer);
        }
        $conversation->state = self::STATE_REVIEW;

        return $this->reviewMessage($config, $conversation, $customer, $botUser);
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
            ."Reply with a number, or type a product name (e.g. *2 halisi*).";
    }

    protected function browseMessage(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        User $botUser,
    ): string {
        $products = collect($conversation->payload['browse_products'] ?? []);
        if ($products->isEmpty()) {
            return $this->startBrowse($config, $conversation, $customer, $botUser);
        }

        $lines = ["Choose a product (in stock only):\n"];
        foreach ($products->values() as $index => $product) {
            $price = $this->orders->formatMoney((float) $product['unit_price']);
            $stock = $product['available_display'] ?? '';
            $lines[] = ($index + 1).". {$product['product_name']} — {$price}".($stock ? " ({$stock})" : '');
        }
        $cartCount = count($this->cartLines($conversation));
        $lines[] = "\n0️⃣ ".($cartCount > 0 ? "Review cart ({$cartCount} items)" : 'Back to menu');
        if ($conversation->payload['browse_has_more'] ?? false) {
            $lines[] = '➡️ Reply *NEXT* for more products';
        }
        $lines[] = "\nReply with item number, or type a product name to search.";

        return implode("\n", $lines);
    }

    protected function cartMessage(WhatsappConversation $conversation): string
    {
        $cart = $this->cartLines($conversation);
        if ($cart === []) {
            return "Your cart is empty.\n\n1️⃣ Add items\n2️⃣ Review order\n3️⃣ Clear cart";
        }

        $lines = ["🛒 *Your cart*\n"];
        foreach ($cart as $index => $item) {
            $n = $index + 1;
            $lines[] = "{$n}. ".($item['display'] ?? '').' '.$item['product_name'];
        }
        $lines[] = "\n*Edit cart:*";
        $lines[] = '• *R1*, *R2*… remove a line';
        $lines[] = '• *E1 5*, *E2 10*… change quantity (packaging units)';
        $lines[] = '• Type a product name to add more';
        $lines[] = "\n1️⃣ Browse products";
        $lines[] = '2️⃣ Review & place order';
        $lines[] = '3️⃣ Clear cart';

        return implode("\n", $lines);
    }

    protected function reviewMessage(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        User $botUser,
    ): string {
        $preview = $this->orders->previewCart(
            $customer,
            $botUser,
            $this->gateForConfig($config),
            $this->cartLines($conversation),
        );
        $lines = ["📋 *Order summary*\n", "Customer: *{$customer->customer_name}*\n"];
        foreach ($preview['lines'] as $item) {
            $lines[] = '• '.($item['display'] ?? '').' '.$item['product_name']
                .' — '.$this->orders->formatMoney((float) $item['line_total']);
        }
        $lines[] = "\n*Estimated total: ".$this->orders->formatMoney((float) $preview['estimated_total']).'*';
        if ($preview['stock_warnings'] !== []) {
            $lines[] = "\n⚠️ Stock warnings:";
            foreach ($preview['stock_warnings'] as $warning) {
                $lines[] = '• '.$warning;
            }
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
        return "Thanks — our team has been notified and will assist you during business hours.\n\n"
            .'For urgent orders, please call your sales rep.\n\n'
            .'Reply *MENU* to return to ordering.';
    }

    protected function requestHandoff(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        User $botUser,
        string $rawMessage,
    ): string {
        $this->handoffs->requestHandoff($config, $conversation, $customer, $botUser, trim($rawMessage) ?: null);

        return $this->handoffMessage();
    }

    protected function handleHandoffState(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
    ): string {
        if ($this->handoffs->hasOpenHandoff($config->organizationId, (int) $conversation->id)) {
            if ($this->isGlobalCommand($input, ['MENU', 'HI', 'HELLO', 'START'])) {
                return "Our team is still handling your request. Please wait for them to follow up, or call your sales rep.\n\n"
                    .$this->handoffWaitingMessage();
            }

            return $this->handoffWaitingMessage();
        }

        $conversation->state = self::STATE_MAIN_MENU;

        return "Your request has been resolved.\n\n".$this->mainMenuMessage($customer);
    }

    protected function handoffWaitingMessage(): string
    {
        return "Your message was sent to our team. Someone will assist you during business hours.\n\n"
            .'Reply *MENU* after your request is resolved to place a new order.';
    }

    protected function tryHandleProductText(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
        User $botUser,
    ): ?string {
        if ($this->isReservedCommand($input)) {
            return null;
        }

        $parsed = $this->catalog->parseProductQuery($input);
        if (! $parsed || strlen($parsed['term']) < 2) {
            return null;
        }

        return $this->showSearchResults(
            $config,
            $conversation,
            $customer,
            $botUser,
            $parsed['term'],
            1,
            $parsed['qty'],
        );
    }

    protected function showSearchResults(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        User $botUser,
        string $term,
        int $page,
        ?float $presetQty = null,
    ): string {
        $gate = $this->gateForConfig($config);
        $result = $this->catalog->searchInStock($customer, $botUser, $gate, $term, $page);

        if ($result['total'] === 0) {
            return "No in-stock products matched *{$term}*.\n\nTry another name or reply *1* to browse.\n\n"
                .$this->mainMenuMessage($customer);
        }

        if ($result['total'] === 1 && $presetQty !== null && $presetQty > 0) {
            return $this->addProductToCart($conversation, $result['items'][0], $presetQty);
        }

        if ($result['total'] === 1 && $presetQty === null) {
            return $this->beginQuantityForProduct($conversation, $result['items'][0]);
        }

        $conversation->payload = array_merge($conversation->payload ?? [], [
            'search_results' => $result['items'],
            'search_term' => $term,
            'search_page' => $result['page'],
            'search_has_more' => $result['has_more'],
            'search_preset_qty' => $presetQty,
        ]);
        $conversation->state = self::STATE_SEARCH_RESULTS;

        return $this->searchResultsMessage($conversation);
    }

    protected function searchResultsMessage(WhatsappConversation $conversation): string
    {
        $term = (string) ($conversation->payload['search_term'] ?? '');
        $products = collect($conversation->payload['search_results'] ?? []);
        $lines = ["Results for *{$term}* (in stock):\n"];
        foreach ($products->values() as $index => $product) {
            $price = $this->orders->formatMoney((float) $product['unit_price']);
            $stock = $product['available_display'] ?? '';
            $lines[] = ($index + 1).". {$product['product_name']} — {$price}".($stock ? " ({$stock})" : '');
        }
        if ($conversation->payload['search_has_more'] ?? false) {
            $lines[] = "\n➡️ Reply *NEXT* for more matches";
        }
        $lines[] = "\n0️⃣ Back to menu";
        $lines[] = 'Reply with item number.';

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $product */
    protected function beginQuantityForProduct(WhatsappConversation $conversation, array $product): string
    {
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'pending_product_code' => $product['product_code'],
            'pending_product_name' => $product['product_name'],
            'pending_uom' => $product['uom_snapshot'] ?? null,
        ]);
        $conversation->state = self::STATE_ENTER_QTY;

        $uom = $this->uomFromSnapshot($product['uom_snapshot'] ?? null);
        $label = $uom
            ? ($this->stockUom->formatMixedStockDisplay(1, $uom)['parts'][0]['label'] ?? 'units')
            : 'units';

        return "How many *{$label}* of *{$product['product_name']}*?\n\nReply with a number, or CANCEL to go back.";
    }

    /** @param  array<string, mixed>  $product */
    protected function addProductToCart(WhatsappConversation $conversation, array $product, float $displayQty): string
    {
        $uom = $this->uomFromSnapshot($product['uom_snapshot'] ?? null);
        $factor = max(1.0, (float) ($uom->conversion_factor ?? 1));
        $usesSmall = ($uom->uses_small_packaging ?? true) !== false;
        $baseQty = $usesSmall && $factor > 1 ? $displayQty * $factor : $displayQty;
        $code = (string) $product['product_code'];
        $name = (string) $product['product_name'];

        $cart = $this->cartLines($conversation);
        $cart[] = [
            'product_code' => $code,
            'product_name' => $name,
            'quantity' => $baseQty,
            'display' => $this->stockUom->formatMixedStockDisplay($baseQty, $uom)['text'],
            'line_total' => null,
        ];
        $conversation->payload = array_merge($conversation->payload ?? [], ['cart' => $cart]);
        $conversation->state = self::STATE_CART;

        return "Added ✅\n*{$name}* — ".$this->stockUom->formatMixedStockDisplay($baseQty, $uom)['text']."\n\n".$this->cartMessage($conversation);
    }

    protected function removeCartLine(WhatsappConversation $conversation, Customer $customer, int $lineNumber): string
    {
        $cart = $this->cartLines($conversation);
        if ($lineNumber < 1 || $lineNumber > count($cart)) {
            return "Invalid line number.\n\n".$this->cartMessage($conversation);
        }
        array_splice($cart, $lineNumber - 1, 1);
        $conversation->payload = array_merge($conversation->payload ?? [], ['cart' => $cart]);
        if ($cart === []) {
            $conversation->state = self::STATE_MAIN_MENU;

            return "Cart is empty.\n\n".$this->mainMenuMessage($customer);
        }

        return "Removed line {$lineNumber}.\n\n".$this->cartMessage($conversation);
    }

    protected function editCartLine(
        WhatsappConversation $conversation,
        Customer $customer,
        int $lineNumber,
        float $displayQty,
    ): string {
        if ($displayQty <= 0) {
            return $this->removeCartLine($conversation, $customer, $lineNumber);
        }

        $cart = $this->cartLines($conversation);
        if ($lineNumber < 1 || $lineNumber > count($cart)) {
            return "Invalid line number.\n\n".$this->cartMessage($conversation);
        }

        $line = $cart[$lineNumber - 1];
        $product = Product::query()
            ->with('unit')
            ->where('organization_id', $conversation->organization_id)
            ->where('product_code', $line['product_code'] ?? '')
            ->first();
        $uom = $product?->unit;
        $factor = max(1.0, (float) ($uom?->conversion_factor ?? 1));
        $usesSmall = ($uom?->uses_small_packaging ?? true) !== false;
        $baseQty = $usesSmall && $factor > 1 ? $displayQty * $factor : $displayQty;

        $cart[$lineNumber - 1]['quantity'] = $baseQty;
        $cart[$lineNumber - 1]['display'] = $this->stockUom->formatMixedStockDisplay($baseQty, $uom)['text'];
        $conversation->payload = array_merge($conversation->payload ?? [], ['cart' => $cart]);

        return "Updated line {$lineNumber}.\n\n".$this->cartMessage($conversation);
    }

    protected function gateForConfig(ResolvedWhatsAppConfig $config): CapabilityGate
    {
        $org = Organization::query()->find($config->organizationId);

        return (new CapabilityGate)->forOrganization($org);
    }

    protected function isReservedCommand(string $input): bool
    {
        if (in_array($input, ['1', '2', '3', '4', '0', 'CONFIRM', 'EDIT', 'CANCEL', 'MENU', 'HELP', 'HUMAN', 'NEXT', 'MORE', 'START', 'HI', 'HELLO'], true)) {
            return true;
        }

        if (str_starts_with($input, 'NOTES:')) {
            return true;
        }

        return (bool) (preg_match('/^R\d+$/', $input) || preg_match('/^E\d+\s+\d/', $input));
    }

    /** @return list<array<string, mixed>> */
    protected function cartLines(WhatsappConversation $conversation): array
    {
        $cart = $conversation->payload['cart'] ?? [];

        return is_array($cart) ? $cart : [];
    }

    protected function loadConversation(ResolvedWhatsAppConfig $config, string $phone): WhatsappConversation
    {
        $conversation = WhatsappConversation::query()->firstOrCreate(
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

        if ($conversation->expires_at !== null && $conversation->expires_at->isPast()) {
            $conversation->state = self::STATE_MAIN_MENU;
            $conversation->payload = array_intersect_key(
                $conversation->payload ?? [],
                array_flip(['last_sale_id', 'last_order_num']),
            );
            $conversation->expires_at = now()->addHours((int) config('whatsapp.conversation_ttl_hours', 24));
            $conversation->save();
        }

        return $conversation;
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
        ?array $meta = null,
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
            'meta' => $meta,
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
