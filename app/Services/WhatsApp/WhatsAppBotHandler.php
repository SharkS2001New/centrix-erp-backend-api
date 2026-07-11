<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Uom;
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

    public const STATE_CHOOSE_RW = 'choose_rw';

    public const STATE_ENTER_QTY = 'enter_qty';

    public const STATE_CART = 'cart';

    public const STATE_REVIEW = 'review';

    public const STATE_REPEAT_CONFIRM = 'repeat_confirm';

    public const STATE_TRACK = 'track';

    public const STATE_HANDOFF = 'handoff';

    public const STATE_SEARCH_RESULTS = 'search_results';

    public const STATE_UNKNOWN = 'unknown';

    public const STATE_REGISTER_NAME = 'register_name';

    public const STATE_REGISTER_TOWN = 'register_town';

    public const STATE_REGISTER_ROUTE = 'register_route';

    protected bool $dryRun = false;

    /** Simulator session: ephemeral chat state; never sends Meta WhatsApp messages. */
    protected bool $simulatorSession = false;

    /** @var list<string> */
    protected array $wouldMutate = [];

    /** @var array{order_num: int|null, sale_id: int|null, status: string|null, order_total: float|null}|null */
    protected ?array $lastPlacedOrder = null;

    public function __construct(
        protected CustomerPhoneLookup $customerLookup,
        protected WhatsAppOrderService $orders,
        protected WhatsAppProductCatalogService $catalog,
        protected WhatsAppHandoffService $handoffs,
        protected WhatsAppCustomerRegistrationService $registration,
        protected StockUomDisplayService $stockUom,
        protected MetaWhatsAppClient $whatsapp,
        protected WhatsAppConfigResolver $configResolver,
    ) {}

    /**
     * Platform-admin simulator: same bot replies using org products/customers.
     * Dry run (default) never mutates data. Live mode places real orders/handoffs
     * in the tenant org but still does not send WhatsApp messages.
     *
     * @param  array{state?: string, payload?: array<string, mixed>, customer_num?: string|null, phone?: string}|null  $session
     * @return array{
     *   reply: string,
     *   state: string,
     *   payload: array<string, mixed>,
     *   customer_num: string|null,
     *   customer_name: string|null,
     *   phone: string,
     *   cart: list<array<string, mixed>>,
     *   would_mutate: list<string>,
     *   dry_run: bool,
     *   place_real_orders: bool,
     *   placed_order: array<string, mixed>|null
     * }
     */
    public function simulate(
        ResolvedWhatsAppConfig $config,
        string $fromPhone,
        string $text,
        ?Customer $customer = null,
        ?array $session = null,
        bool $placeRealOrders = false,
    ): array {
        $this->simulatorSession = true;
        $this->dryRun = ! $placeRealOrders;
        $this->wouldMutate = [];
        $this->lastPlacedOrder = null;

        try {
            $botUser = $this->configResolver->botUser($config);
            if (! $botUser) {
                return [
                    'reply' => 'Ordering is temporarily unavailable. Please contact the office for help.',
                    'state' => self::STATE_UNKNOWN,
                    'payload' => [],
                    'customer_num' => $customer?->customer_num,
                    'customer_name' => $customer?->customer_name,
                    'phone' => $fromPhone,
                    'cart' => [],
                    'would_mutate' => [],
                    'dry_run' => $this->dryRun,
                    'place_real_orders' => $placeRealOrders,
                    'placed_order' => null,
                ];
            }

            $normalizedPhone = PhoneNumber::normalize($fromPhone) ?? $fromPhone;
            $conversation = $this->makeEphemeralConversation($config, $normalizedPhone, $session);
            if ($customer) {
                $conversation->customer_num = $customer->customer_num;
            } else {
                $customer = $this->resolveCustomer($config, $conversation, $normalizedPhone);
            }

            $reply = $this->dispatch(
                $config,
                $botUser,
                $conversation,
                $customer,
                $this->normalizeInput($text),
                $text,
            );

            return [
                'reply' => $reply,
                'state' => (string) ($conversation->state ?? self::STATE_UNKNOWN),
                'payload' => is_array($conversation->payload) ? $conversation->payload : [],
                'customer_num' => $customer?->customer_num,
                'customer_name' => $customer?->customer_name,
                'phone' => $normalizedPhone,
                'cart' => $this->cartLines($conversation),
                'would_mutate' => $this->wouldMutate,
                'dry_run' => $this->dryRun,
                'place_real_orders' => $placeRealOrders,
                'placed_order' => $this->lastPlacedOrder,
            ];
        } finally {
            $this->dryRun = false;
            $this->simulatorSession = false;
            $this->wouldMutate = [];
            $this->lastPlacedOrder = null;
        }
    }

    /**
     * @param  array{state?: string, payload?: array<string, mixed>, customer_num?: string|null, phone?: string}|null  $session
     */
    protected function makeEphemeralConversation(
        ResolvedWhatsAppConfig $config,
        string $phone,
        ?array $session,
    ): WhatsappConversation {
        $conversation = new WhatsappConversation([
            'organization_id' => $config->organizationId,
            'phone' => $session['phone'] ?? $phone,
            'customer_num' => $session['customer_num'] ?? null,
            'state' => $session['state'] ?? self::STATE_MAIN_MENU,
            'payload' => is_array($session['payload'] ?? null) ? $session['payload'] : [],
            'last_message_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
        // Ensure Eloquent treats this as unsaved so accidental save() is obvious in tests.
        $conversation->exists = false;

        return $conversation;
    }

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
        if ($this->isGreetingOrMenuCommand($input)) {
            if ($customer) {
                $conversation->state = self::STATE_MAIN_MENU;

                return $this->mainMenuMessage($customer);
            }

            $conversation->state = self::STATE_UNKNOWN;

            return $this->unknownCustomerMessage($config);
        }

        if ($input === 'CANCEL') {
            if ($customer) {
                $conversation->payload = [];
                $conversation->state = self::STATE_MAIN_MENU;

                return "Cart cleared.\n\n".$this->mainMenuMessage($customer);
            }

            $conversation->payload = array_diff_key(
                $conversation->payload ?? [],
                array_flip([
                    'register_name',
                    'register_town',
                    'register_route_id',
                    'register_routes',
                ]),
            );
            $conversation->state = self::STATE_UNKNOWN;

            return $this->unknownCustomerMessage($config);
        }

        if (! $customer) {
            return match ($conversation->state) {
                self::STATE_REGISTER_NAME => $this->handleRegisterName($config, $conversation, $botUser, $input, $rawText),
                self::STATE_REGISTER_TOWN => $this->handleRegisterTown($config, $conversation, $botUser, $input, $rawText),
                self::STATE_REGISTER_ROUTE => $this->handleRegisterRoute($config, $conversation, $botUser, $input, $rawText),
                self::STATE_HANDOFF => $this->handleHandoffState($config, $conversation, null, $input),
                default => $this->handleUnknownCustomer($config, $conversation, $botUser, $input, $rawText),
            };
        }

        return match ($conversation->state) {
            self::STATE_MAIN_MENU => $this->handleMainMenu($config, $conversation, $customer, $input, $rawText, $botUser),
            self::STATE_BROWSE => $this->handleBrowse($config, $conversation, $customer, $input, $botUser),
            self::STATE_SEARCH_RESULTS => $this->handleSearchResults($config, $conversation, $customer, $input, $botUser),
            self::STATE_CHOOSE_RW => $this->handleChooseRw($conversation, $customer, $input),
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
        $product = $this->productFromContinuedListNumber(
            $products,
            (int) $input,
            (int) ($conversation->payload['browse_page'] ?? 1),
            (int) ($conversation->payload['browse_per_page'] ?? WhatsAppProductCatalogService::PER_PAGE),
        );
        if ($product !== null) {
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
        $product = $this->productFromContinuedListNumber(
            $products,
            (int) $input,
            (int) ($conversation->payload['search_page'] ?? 1),
            (int) ($conversation->payload['search_per_page'] ?? WhatsAppProductCatalogService::PER_PAGE),
        );
        if ($product !== null) {
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

    protected function handleChooseRw(
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
    ): string {
        $choice = strtoupper(trim($input));
        if (in_array($choice, ['CANCEL', '0'], true)) {
            $conversation->payload = array_merge($conversation->payload ?? [], [
                'pending_product_code' => null,
                'pending_product_name' => null,
                'pending_uom' => null,
                'pending_sell_on_retail' => null,
                'pending_wholesale_unit_price' => null,
                'pending_retail_unit_price' => null,
                'pending_on_wholesale_retail' => null,
            ]);
            $conversation->state = self::STATE_MAIN_MENU;

            return $this->mainMenuMessage($customer);
        }

        $isRetail = in_array($choice, ['R', 'RETAIL', '1'], true);
        $isWholesale = in_array($choice, ['W', 'WHOLESALE', '2'], true);
        if (! $isRetail && ! $isWholesale) {
            return $this->chooseRwMessage($conversation);
        }

        $conversation->payload = array_merge($conversation->payload ?? [], [
            'pending_on_wholesale_retail' => $isRetail ? 1 : 0,
        ]);

        return $this->promptQuantityForPending($conversation);
    }

    protected function handleEnterQty(
        WhatsappConversation $conversation,
        Customer $customer,
        string $input,
    ): string {
        if (in_array(strtoupper(trim($input)), ['CANCEL', '0'], true)) {
            $conversation->payload = array_merge($conversation->payload ?? [], [
                'pending_product_code' => null,
                'pending_product_name' => null,
                'pending_uom' => null,
                'pending_on_wholesale_retail' => null,
                'pending_preset_qty' => null,
            ]);
            $conversation->state = self::STATE_MAIN_MENU;

            return $this->mainMenuMessage($customer);
        }

        if (! is_numeric($input)) {
            return 'Please reply with a number only (e.g. 10), or CANCEL.';
        }

        $displayQty = (float) $input;
        if ($displayQty <= 0) {
            return 'Quantity must be at least 1.';
        }

        return $this->addPendingProductToCart($conversation, $displayQty);
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

        try {
            $lines = $this->placeOrderLines($conversation);
            if ($lines === []) {
                return "Your cart is empty. Reply *EDIT* or *1* to add items.\n\n"
                    .$this->mainMenuMessage($customer);
            }

            if ($this->dryRun) {
                $this->wouldMutate[] = 'place_order';
                $previewTotal = (float) ($preview['estimated_total'] ?? 0);
                $conversation->payload = [
                    'last_sale_id' => null,
                    'last_order_num' => 'TEST-DRY-RUN',
                    'notes' => $conversation->payload['notes'] ?? null,
                ];
                $conversation->state = self::STATE_MAIN_MENU;

                $warningNote = $preview['stock_warnings'] !== []
                    ? "\n⚠️ Stock notes (would still place in live mode):\n• "
                        .implode("\n• ", $preview['stock_warnings'])."\n"
                    : '';

                return "✅ *TEST MODE — order not placed*\n\n"
                    ."Would create WhatsApp order for *{$customer->customer_name}* "
                    .'('.count($lines).' line(s)'
                    .($previewTotal > 0 ? ', total ~'.$this->orders->formatMoney($previewTotal) : '')
                    .").\n"
                    .$warningNote
                    ."No sale, stock, or ledger changes were made.\n\n"
                    .$this->mainMenuMessage($customer);
            }

            $result = $this->orders->placeOrder(
                $botUser,
                $customer,
                $lines,
                $conversation->payload['notes'] ?? null,
            );

            if (empty($result['order_num']) && empty($result['sale_id'])) {
                throw new InvalidArgumentException(
                    'Checkout finished without creating an order. Check sales permissions and stock settings.',
                );
            }

            $this->lastPlacedOrder = $result;
            $conversation->payload = [
                'last_sale_id' => $result['sale_id'],
                'last_order_num' => $result['order_num'],
            ];
            $conversation->state = self::STATE_MAIN_MENU;

            $livePrefix = $this->simulatorSession
                ? "✅ *Live test order placed*\n\n"
                : "✅ *Order placed successfully*\n\n";

            $stockNote = $preview['stock_warnings'] !== []
                ? "\n⚠️ Stock notes at confirm time:\n• ".implode("\n• ", $preview['stock_warnings'])."\n"
                : '';

            return $livePrefix
                ."Order #*{$result['order_num']}*\n"
                .'Total: *'.$this->orders->formatMoney((float) ($result['order_total'] ?? 0))."*\n"
                .'Status: '.ucfirst(str_replace('_', ' ', (string) ($result['status'] ?? 'received')))
                .$stockNote."\n\n"
                .$this->mainMenuMessage($customer);
        } catch (InvalidArgumentException $e) {
            $this->recordPlaceOrderFailure($config, $conversation, $e);

            return "❌ Could not place order: {$e->getMessage()}\n\n"
                .$this->reviewMessage($config, $conversation, $customer, $botUser);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->filter()->first()
                ?: ($e->getMessage() ?: 'Validation failed while placing the order.');
            $this->recordPlaceOrderFailure($config, $conversation, $e, (string) $message);

            return "❌ Could not place order: {$message}\n\n"
                .$this->reviewMessage($config, $conversation, $customer, $botUser);
        } catch (\Throwable $e) {
            report($e);
            $this->recordPlaceOrderFailure($config, $conversation, $e);

            return "❌ Something went wrong placing your order. The error was logged for support.\n"
                ."Please try again or reply *4* to talk to our team.\n\n"
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
            $lines = $this->placeOrderLines($conversation);

            if ($this->dryRun) {
                $this->wouldMutate[] = 'place_order';
                $conversation->payload = [];
                $conversation->state = self::STATE_MAIN_MENU;

                return "✅ *TEST MODE — order not placed*\n\n"
                    ."Would repeat last order for *{$customer->customer_name}* (".count($lines)." line(s)).\n"
                    ."No sale or stock changes were made.\n\n"
                    .$this->mainMenuMessage($customer);
            }

            $result = $this->orders->placeOrder($botUser, $customer, $lines);
            if (empty($result['order_num']) && empty($result['sale_id'])) {
                throw new InvalidArgumentException(
                    'Checkout finished without creating an order. Check sales permissions and stock settings.',
                );
            }
            $this->lastPlacedOrder = $result;
            $conversation->payload = [];
            $conversation->state = self::STATE_MAIN_MENU;

            $livePrefix = $this->simulatorSession
                ? "✅ *Live test order placed*\n\n"
                : "✅ *Order placed successfully*\n\n";

            return $livePrefix
                ."Order #*{$result['order_num']}*\n"
                .'Total: *'.$this->orders->formatMoney((float) ($result['order_total'] ?? 0))."*\n\n"
                .$this->mainMenuMessage($customer);
        } catch (InvalidArgumentException $e) {
            $this->recordPlaceOrderFailure($config, $conversation, $e);

            return "❌ Could not repeat your order: {$e->getMessage()}\n\nReply *4* for help, or *MENU* to start over.";
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->filter()->first()
                ?: ($e->getMessage() ?: 'Validation failed while placing the order.');
            $this->recordPlaceOrderFailure($config, $conversation, $e, (string) $message);

            return "❌ Could not repeat your order: {$message}\n\nReply *4* for help, or *MENU* to start over.";
        } catch (\Throwable $e) {
            report($e);
            $this->recordPlaceOrderFailure($config, $conversation, $e);

            return '❌ Could not repeat your order. The error was logged for support. Reply *4* for help, or *MENU* to start over.';
        }
    }

    protected function recordPlaceOrderFailure(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        \Throwable $e,
        ?string $messageOverride = null,
    ): void {
        if ($this->dryRun) {
            return;
        }

        $message = $messageOverride ?: ($e->getMessage() !== '' ? $e->getMessage() : class_basename($e));

        $this->orders->logOrderFailure(
            $config->organizationId,
            $conversation->id ? (int) $conversation->id : null,
            (string) $conversation->phone,
            $message,
            $this->cartLines($conversation),
            $e,
            $this->simulatorSession,
        );
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
            'browse_per_page' => $result['per_page'],
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
            $body .= '• '.$this->orders->formatSummaryLine($line)."\n";
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
        $page = max(1, (int) ($conversation->payload['browse_page'] ?? 1));
        $perPage = max(1, (int) ($conversation->payload['browse_per_page'] ?? WhatsAppProductCatalogService::PER_PAGE));
        $startNumber = (($page - 1) * $perPage) + 1;
        foreach ($products->values() as $index => $product) {
            $lines[] = ($startNumber + $index).'. '.$this->formatProductListLine($product);
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
            $lines[] = "{$n}. ".$this->orders->formatSummaryLine($item);
        }
        $lines[] = "\n*Edit cart:*";
        $lines[] = '• *R1*, *R2*… remove a line';
        $lines[] = '• *E1 5*, *E2 10*… change quantity';
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
            $lines[] = '• '.$this->orders->formatSummaryLine($item);
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
        if ($preview['stock_warnings'] !== []) {
            $lines[] = '(CONFIRM still places the order even when stock warnings are shown)';
        }
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

    protected function unknownCustomerMessage(ResolvedWhatsAppConfig $config): string
    {
        $office = $this->registration->officeContactLine($config->organizationId, $config->branchId);

        return "We don't recognize this WhatsApp number yet.\n\n"
            ."1️⃣ Register your shop (quick signup)\n"
            ."2️⃣ Talk to someone / call the office\n\n"
            ."{$office}\n\n"
            .'Reply *1* to register, or *2* for help.';
    }

    protected function handleUnknownCustomer(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        User $botUser,
        string $input,
        string $rawText,
    ): string {
        $conversation->state = self::STATE_UNKNOWN;

        if (in_array($input, ['1', 'REGISTER', 'SIGNUP', 'SIGN UP'], true)) {
            return $this->startRegistration($conversation);
        }

        if (in_array($input, ['2', 'HUMAN', 'HELP', 'CALL'], true)) {
            return $this->requestUnknownContact($config, $conversation, $botUser, $rawText);
        }

        return $this->unknownCustomerMessage($config);
    }

    protected function startRegistration(WhatsappConversation $conversation): string
    {
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'register_name' => null,
            'register_town' => null,
            'register_route_id' => null,
            'register_routes' => null,
        ]);
        $conversation->state = self::STATE_REGISTER_NAME;

        return "Let's register your shop 📝\n\n"
            ."What is your *shop / business name*?\n\n"
            .'Reply with the name, or CANCEL to go back.';
    }

    protected function handleRegisterName(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        User $botUser,
        string $input,
        string $rawText,
    ): string {
        $name = trim($rawText);
        if ($name === '' || mb_strlen($name) < 2) {
            return 'Please reply with your shop or business name (at least 2 characters), or CANCEL.';
        }

        if (in_array(strtoupper($name), ['1', '2', 'MENU', 'HELP', 'HUMAN'], true)) {
            return 'That looks like a menu choice. Please send your *shop / business name*, or CANCEL.';
        }

        $conversation->payload = array_merge($conversation->payload ?? [], [
            'register_name' => $name,
        ]);
        $conversation->state = self::STATE_REGISTER_TOWN;

        return "Thanks. Which *town / area* is the shop in?\n\n"
            .'Reply with the town, *SKIP* to leave blank, or CANCEL.';
    }

    protected function handleRegisterTown(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        User $botUser,
        string $input,
        string $rawText,
    ): string {
        $town = trim($rawText);
        if (strtoupper($town) === 'SKIP' || $town === '0') {
            $town = '';
        }

        $conversation->payload = array_merge($conversation->payload ?? [], [
            'register_town' => $town !== '' ? $town : null,
        ]);

        $gate = $this->gateForConfig($config);
        if ($this->registration->requiresRoute($gate)) {
            $routes = $this->registration->listRoutes($config->organizationId, $config->branchId);
            if ($routes === []) {
                return $this->failRegistration(
                    $config,
                    $conversation,
                    $botUser,
                    $rawText,
                    'No delivery routes are set up yet, so we cannot finish signup here.',
                );
            }

            $conversation->payload = array_merge($conversation->payload ?? [], [
                'register_routes' => $routes,
            ]);
            $conversation->state = self::STATE_REGISTER_ROUTE;

            $lines = ["Which *delivery route* should we assign?\n"];
            foreach ($routes as $index => $route) {
                $lines[] = ($index + 1).'. '.$route['route_name'];
            }
            $lines[] = "\nReply with the route number, or CANCEL.";

            return implode("\n", $lines);
        }

        return $this->finishRegistration($config, $conversation, $botUser, $rawText);
    }

    protected function handleRegisterRoute(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        User $botUser,
        string $input,
        string $rawText,
    ): string {
        $routes = $conversation->payload['register_routes'] ?? [];
        if (! is_array($routes) || $routes === []) {
            return $this->failRegistration(
                $config,
                $conversation,
                $botUser,
                $rawText,
                'Route list expired. Please start registration again.',
            );
        }

        $index = (int) $input;
        if ($index < 1 || $index > count($routes)) {
            return "Pick a number from the route list (1–".count($routes).'), or CANCEL.';
        }

        $route = $routes[$index - 1];
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'register_route_id' => (int) ($route['id'] ?? 0),
        ]);

        return $this->finishRegistration($config, $conversation, $botUser, $rawText);
    }

    protected function finishRegistration(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        User $botUser,
        string $rawText,
    ): string {
        if ($this->dryRun) {
            $this->wouldMutate[] = 'register_customer';
            $previewName = (string) ($conversation->payload['register_name'] ?? 'your shop');
            $conversation->state = self::STATE_UNKNOWN;
            $conversation->payload = array_diff_key(
                $conversation->payload ?? [],
                array_flip(['register_name', 'register_town', 'register_route_id', 'register_routes']),
            );

            return "✅ *TEST MODE — customer not created*\n\n"
                ."In production we would register *{$previewName}* against this WhatsApp number.\n\n"
                .$this->unknownCustomerMessage($config);
        }

        $result = $this->registration->register(
            $config,
            $botUser,
            $this->gateForConfig($config),
            [
                'customer_name' => (string) ($conversation->payload['register_name'] ?? ''),
                'phone' => (string) $conversation->phone,
                'town' => $conversation->payload['register_town'] ?? null,
                'route_id' => $conversation->payload['register_route_id'] ?? null,
                'branch_id' => $config->branchId,
            ],
        );

        if (! ($result['ok'] ?? false)) {
            return $this->failRegistration(
                $config,
                $conversation,
                $botUser,
                $rawText,
                (string) ($result['message'] ?? 'Registration failed.'),
            );
        }

        /** @var Customer $customer */
        $customer = $result['customer'];
        $conversation->customer_num = $customer->customer_num;
        $conversation->payload = array_diff_key(
            $conversation->payload ?? [],
            array_flip(['register_name', 'register_town', 'register_route_id', 'register_routes']),
        );
        $conversation->state = self::STATE_MAIN_MENU;

        return "✅ *Registered successfully*\n\n"
            ."Welcome *{$customer->customer_name}*!\n"
            .'Your WhatsApp number is now linked. You can place orders below.'
            ."\n\n".$this->mainMenuMessage($customer);
    }

    protected function failRegistration(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        User $botUser,
        string $rawText,
        string $reason,
    ): string {
        $conversation->state = self::STATE_UNKNOWN;
        $conversation->payload = array_diff_key(
            $conversation->payload ?? [],
            array_flip(['register_name', 'register_town', 'register_route_id', 'register_routes']),
        );

        $office = $this->registration->officeContactLine($config->organizationId, $config->branchId);

        $this->requestUnknownContact(
            $config,
            $conversation,
            $botUser,
            trim($rawText) !== '' ? $rawText : $reason,
            notifyOnly: true,
        );

        return "We could not finish registration automatically.\n"
            ."{$reason}\n\n"
            ."A team member will try to contact you, or {$office}\n\n"
            .'Reply *1* to try again, or *2* for help.';
    }

    protected function requestUnknownContact(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        User $botUser,
        string $rawMessage,
        bool $notifyOnly = false,
    ): string {
        $office = $this->registration->officeContactLine($config->organizationId, $config->branchId);

        if ($this->dryRun || $this->simulatorSession) {
            $this->wouldMutate[] = 'handoff';
            if (! $notifyOnly) {
                $conversation->state = self::STATE_HANDOFF;
            }

            return "🧑‍💼 *TEST MODE — handoff not created*\n\n"
                ."In production staff would be notified for *{$conversation->phone}*.\n"
                ."{$office}\n\n"
                .($notifyOnly ? '' : $this->handoffWaitingMessage());
        }

        $this->handoffs->requestHandoff(
            $config,
            $conversation,
            null,
            $botUser,
            trim($rawMessage) !== '' ? trim($rawMessage) : 'Unknown number requested help / registration failed.',
        );

        if ($notifyOnly) {
            // Keep unknown state so they can retry; handoff service sets handoff state — restore.
            $conversation->state = self::STATE_UNKNOWN;
            if (! $this->dryRun && ! $this->simulatorSession && $conversation->exists) {
                $conversation->save();
            }
        }

        return "Thanks — our team has been notified.\n\n"
            ."{$office}\n\n"
            .($notifyOnly
                ? 'Reply *1* to try registering again.'
                : $this->handoffWaitingMessage());
    }

    protected function handoffMessage(ResolvedWhatsAppConfig $config): string
    {
        $office = $this->registration->officeContactLine($config->organizationId, $config->branchId);

        return "Thanks — our team has been notified and will assist you during business hours.\n\n"
            ."{$office}\n\n"
            .'Reply *MENU* to return to ordering.';
    }

    protected function requestHandoff(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        ?Customer $customer,
        User $botUser,
        string $rawMessage,
    ): string {
        // Platform simulator never writes handoff rows (ephemeral conversation has no id).
        if ($this->dryRun || $this->simulatorSession) {
            $this->wouldMutate[] = 'handoff';
            $conversation->state = self::STATE_HANDOFF;
            $label = $customer?->customer_name ?? $conversation->phone;

            return "🧑‍💼 *TEST MODE — handoff not created*\n\n"
                ."In production this would notify staff for *{$label}*.\n"
                ."No handoff or notifications were saved.\n\n"
                .$this->handoffWaitingMessage();
        }

        $this->handoffs->requestHandoff($config, $conversation, $customer, $botUser, trim($rawMessage) ?: null);

        return $this->handoffMessage($config);
    }

    protected function handleHandoffState(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        ?Customer $customer,
        string $input,
    ): string {
        $conversationId = (int) ($conversation->id ?? 0);
        $hasOpen = $conversationId > 0
            && $this->handoffs->hasOpenHandoff($config->organizationId, $conversationId);

        if ($hasOpen) {
            if ($this->isGreetingOrMenuCommand($input)) {
                return "Our team is still handling your request. Please wait for them to follow up.\n\n"
                    .$this->registration->officeContactLine($config->organizationId, $config->branchId)."\n\n"
                    .$this->handoffWaitingMessage();
            }

            return $this->handoffWaitingMessage();
        }

        if ($customer) {
            $conversation->state = self::STATE_MAIN_MENU;

            return "Your request has been resolved.\n\n".$this->mainMenuMessage($customer);
        }

        $conversation->state = self::STATE_UNKNOWN;

        return "Your request has been resolved.\n\n".$this->unknownCustomerMessage($config);
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
            'search_per_page' => $result['per_page'],
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
        $page = max(1, (int) ($conversation->payload['search_page'] ?? 1));
        $perPage = max(1, (int) ($conversation->payload['search_per_page'] ?? WhatsAppProductCatalogService::PER_PAGE));
        $startNumber = (($page - 1) * $perPage) + 1;
        $lines = ["Results for *{$term}* (in stock):\n"];
        foreach ($products->values() as $index => $product) {
            $lines[] = ($startNumber + $index).'. '.$this->formatProductListLine($product);
        }
        if ($conversation->payload['search_has_more'] ?? false) {
            $lines[] = "\n➡️ Reply *NEXT* for more matches";
        }
        $lines[] = "\n0️⃣ Back to menu";
        $lines[] = 'Reply with item number, or type another product name to search.';

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $product */
    protected function formatProductListLine(array $product): string
    {
        $name = (string) ($product['product_name'] ?? $product['product_code'] ?? 'Product');
        $stock = (string) ($product['available_display'] ?? '');
        $sellOnRetail = ! empty($product['sell_on_retail']);
        $wholesale = $this->orders->formatMoney((float) ($product['wholesale_unit_price'] ?? $product['unit_price'] ?? 0));

        if ($sellOnRetail && isset($product['retail_unit_price'])) {
            $retail = $this->orders->formatMoney((float) $product['retail_unit_price']);
            $pricePart = "W {$wholesale} / R {$retail}";
        } else {
            $pricePart = $wholesale;
        }

        return "{$name} — {$pricePart}".($stock !== '' ? " ({$stock})" : '');
    }

    /** @param  array<string, mixed>  $product */
    protected function beginQuantityForProduct(
        WhatsappConversation $conversation,
        array $product,
        ?float $presetQty = null,
    ): string {
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'pending_product_code' => $product['product_code'],
            'pending_product_name' => $product['product_name'],
            'pending_uom' => $product['uom_snapshot'] ?? null,
            'pending_sell_on_retail' => ! empty($product['sell_on_retail']),
            'pending_wholesale_unit_price' => $product['wholesale_unit_price'] ?? $product['unit_price'] ?? null,
            'pending_retail_unit_price' => $product['retail_unit_price'] ?? null,
            'pending_preset_qty' => $presetQty,
            'pending_on_wholesale_retail' => null,
        ]);

        if (! empty($product['sell_on_retail'])) {
            $conversation->state = self::STATE_CHOOSE_RW;

            return $this->chooseRwMessage($conversation);
        }

        $conversation->payload = array_merge($conversation->payload ?? [], [
            'pending_on_wholesale_retail' => 0,
        ]);

        if ($presetQty !== null && $presetQty > 0) {
            return $this->addPendingProductToCart($conversation, $presetQty);
        }

        return $this->promptQuantityForPending($conversation);
    }

    protected function chooseRwMessage(WhatsappConversation $conversation): string
    {
        $name = (string) ($conversation->payload['pending_product_name'] ?? 'this product');
        $wholesale = $conversation->payload['pending_wholesale_unit_price'] ?? null;
        $retail = $conversation->payload['pending_retail_unit_price'] ?? null;
        $priceHint = '';
        if ($wholesale !== null || $retail !== null) {
            $parts = [];
            if ($wholesale !== null) {
                $parts[] = 'W '.$this->orders->formatMoney((float) $wholesale);
            }
            if ($retail !== null) {
                $parts[] = 'R '.$this->orders->formatMoney((float) $retail);
            }
            $priceHint = ' ('.implode(' / ', $parts).')';
        }

        return "*{$name}* is sold retail and wholesale{$priceHint}.\n\n"
            ."Reply *R* for Retail (from shop)\n"
            ."Reply *W* for Wholesale (from store)\n"
            .'or CANCEL to go back.';
    }

    protected function promptQuantityForPending(WhatsappConversation $conversation): string
    {
        $presetQty = $conversation->payload['pending_preset_qty'] ?? null;
        if ($presetQty !== null && (float) $presetQty > 0) {
            return $this->addPendingProductToCart($conversation, (float) $presetQty);
        }

        $name = (string) ($conversation->payload['pending_product_name'] ?? 'product');
        $isRetail = ! empty($conversation->payload['pending_on_wholesale_retail']);
        $uom = $this->uomFromSnapshot($conversation->payload['pending_uom'] ?? null);
        $label = $this->quantityPromptLabel($uom, $isRetail);
        $rw = $isRetail ? 'R' : 'W';

        $conversation->state = self::STATE_ENTER_QTY;

        return "How many *{$label}* of *{$name}* ({$rw})?\n\nReply with a number, or CANCEL to go back.";
    }

    protected function quantityPromptLabel(?object $uom, bool $isRetail): string
    {
        if (! $uom) {
            return 'units';
        }

        if ($isRetail) {
            return (string) ($uom->small_packaging_label ?? $uom->full_name ?? 'units');
        }

        $factor = max(1.0, (float) ($uom->conversion_factor ?? 1));
        $usesSmall = ($uom->uses_small_packaging ?? true) !== false;
        if ($usesSmall && $factor > 1) {
            return (string) ($uom->full_name ?? 'packs');
        }

        return (string) ($uom->full_name ?? $uom->small_packaging_label ?? 'units');
    }

    /** @param  array<string, mixed>  $product */
    protected function addProductToCart(WhatsappConversation $conversation, array $product, float $displayQty): string
    {
        return $this->beginQuantityForProduct($conversation, $product, $displayQty);
    }

    protected function addPendingProductToCart(WhatsappConversation $conversation, float $displayQty): string
    {
        $code = (string) ($conversation->payload['pending_product_code'] ?? '');
        $name = (string) ($conversation->payload['pending_product_name'] ?? $code);
        $uom = $this->uomFromSnapshot($conversation->payload['pending_uom'] ?? null);
        $isRetail = ! empty($conversation->payload['pending_on_wholesale_retail']);
        $factor = max(1.0, (float) ($uom?->conversion_factor ?? 1));
        $usesSmall = ($uom?->uses_small_packaging ?? true) !== false;

        if ($isRetail) {
            $baseQty = $displayQty;
        } else {
            $baseQty = $usesSmall && $factor > 1 ? $displayQty * $factor : $displayQty;
        }

        $product = Product::query()
            ->with('unit')
            ->where('organization_id', $conversation->organization_id)
            ->where('product_code', $code)
            ->whereNull('deleted_at')
            ->first();

        $display = $product
            ? app(\App\Services\Sales\SaleLineQuantityDisplayService::class)
                ->formatLineQtyDisplay($baseQty, $product, $isRetail)
            : $this->stockUom->formatMixedStockDisplay($baseQty, $uom)['text'];

        $lineTotal = null;
        $unitPrice = null;
        if ($product) {
            $pricing = app(\App\Services\Sales\PosLinePricingService::class);
            $qtyDisplay = app(\App\Services\Sales\SaleLineQuantityDisplayService::class);
            $lineTotal = $pricing->lineTotalBeforeDiscount(
                $product,
                $baseQty,
                $isRetail,
                null,
                (int) $conversation->organization_id,
            );
            $unitPrice = $qtyDisplay->displayUnitPrice($baseQty, $lineTotal, $product, $isRetail);
        }

        $cart = $this->cartLines($conversation);
        $cart[] = [
            'product_code' => $code,
            'product_name' => $name,
            'quantity' => $baseQty,
            'display' => $display,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'on_wholesale_retail' => $isRetail ? 1 : 0,
            'rw' => $isRetail ? 'R' : 'W',
        ];
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'cart' => $cart,
            'pending_product_code' => null,
            'pending_product_name' => null,
            'pending_uom' => null,
            'pending_on_wholesale_retail' => null,
            'pending_sell_on_retail' => null,
            'pending_preset_qty' => null,
        ]);
        $conversation->state = self::STATE_CART;

        $rw = $isRetail ? 'R' : 'W';

        return "Added ✅\n*{$name}* — {$display} ({$rw})"
            .($unitPrice !== null ? ' @ '.$this->orders->formatMoney((float) $unitPrice) : '')
            ."\n\n".$this->cartMessage($conversation);
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
        $isRetail = ! empty($line['on_wholesale_retail']);
        $product = Product::query()
            ->with('unit')
            ->where('organization_id', $conversation->organization_id)
            ->where('product_code', $line['product_code'] ?? '')
            ->first();
        $uom = $product?->unit;
        $factor = max(1.0, (float) ($uom?->conversion_factor ?? 1));
        $usesSmall = ($uom?->uses_small_packaging ?? true) !== false;

        if ($isRetail) {
            $baseQty = $displayQty;
        } else {
            $baseQty = $usesSmall && $factor > 1 ? $displayQty * $factor : $displayQty;
        }

        $display = $product
            ? app(\App\Services\Sales\SaleLineQuantityDisplayService::class)
                ->formatLineQtyDisplay($baseQty, $product, $isRetail)
            : $this->stockUom->formatMixedStockDisplay($baseQty, $uom)['text'];

        $lineTotal = $line['line_total'] ?? null;
        $unitPrice = $line['unit_price'] ?? null;
        if ($product) {
            $pricing = app(\App\Services\Sales\PosLinePricingService::class);
            $qtyDisplay = app(\App\Services\Sales\SaleLineQuantityDisplayService::class);
            $lineTotal = $pricing->lineTotalBeforeDiscount(
                $product,
                $baseQty,
                $isRetail,
                null,
                (int) $conversation->organization_id,
            );
            $unitPrice = $qtyDisplay->displayUnitPrice($baseQty, $lineTotal, $product, $isRetail);
        }

        $cart[$lineNumber - 1]['quantity'] = $baseQty;
        $cart[$lineNumber - 1]['display'] = $display;
        $cart[$lineNumber - 1]['unit_price'] = $unitPrice;
        $cart[$lineNumber - 1]['line_total'] = $lineTotal;
        $cart[$lineNumber - 1]['rw'] = $isRetail ? 'R' : 'W';
        $conversation->payload = array_merge($conversation->payload ?? [], ['cart' => $cart]);

        return "Updated line {$lineNumber}.\n\n".$this->cartMessage($conversation);
    }

    /** @return list<array{product_code: string, quantity: float, on_wholesale_retail: int}> */
    protected function placeOrderLines(WhatsappConversation $conversation): array
    {
        return array_map(
            fn (array $line) => [
                'product_code' => $line['product_code'],
                'quantity' => (float) $line['quantity'],
                'on_wholesale_retail' => ! empty($line['on_wholesale_retail']) ? 1 : 0,
            ],
            $this->cartLines($conversation),
        );
    }

    protected function gateForConfig(ResolvedWhatsAppConfig $config): CapabilityGate
    {
        $org = Organization::query()->find($config->organizationId);

        return (new CapabilityGate)->forOrganization($org);
    }

    protected function isReservedCommand(string $input): bool
    {
        if (in_array($input, ['1', '2', '3', '4', '0', 'CONFIRM', 'EDIT', 'CANCEL', 'MENU', 'HELP', 'HUMAN', 'NEXT', 'MORE', 'START', 'HI', 'HELLO', 'HEY', 'HOLA', 'HABARI', 'JAMBO', 'R', 'W', 'RETAIL', 'WHOLESALE', 'REGISTER', 'SIGNUP', 'CALL', 'GOOD MORNING', 'GOOD AFTERNOON', 'GOOD EVENING'], true)) {
            return true;
        }

        if ($this->isGreetingOrMenuCommand($input)) {
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
        // Simulator keeps state in cache only — never writes conversation rows.
        if ($this->dryRun || $this->simulatorSession) {
            if ($customer && ! $conversation->customer_num) {
                $conversation->customer_num = $customer->customer_num;
            }

            return;
        }

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
        if ($this->dryRun || $this->simulatorSession) {
            return;
        }

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

    /**
     * Map a continued list number (9, 10, …) back to the current page item.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>|iterable<int, array<string, mixed>>  $products
     * @return array<string, mixed>|null
     */
    protected function productFromContinuedListNumber(
        iterable $products,
        int $number,
        int $page,
        int $perPage,
    ): ?array {
        if ($number < 1) {
            return null;
        }

        $items = collect($products)->values();
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;
        $localIndex = $number - $offset;
        if ($localIndex < 1 || $localIndex > $items->count()) {
            return null;
        }

        $product = $items[$localIndex - 1] ?? null;

        return is_array($product) ? $product : null;
    }

    /**
     * True for menu/start commands and greetings, including "Hello Omega" / "Hi there".
     * Messages with digits after a greeting (e.g. "HI 2 SUGAR") stay product searches.
     */
    protected function isGreetingOrMenuCommand(string $input): bool
    {
        if ($this->isGlobalCommand($input, [
            'MENU',
            'HI',
            'HELLO',
            'HEY',
            'START',
            'HOLA',
            'HABARI',
            'JAMBO',
            'GOOD MORNING',
            'GOOD AFTERNOON',
            'GOOD EVENING',
        ])) {
            return true;
        }

        return (bool) preg_match(
            '/^(HI|HELLO|HEY|HOLA|HABARI|JAMBO|GOOD\s+(MORNING|AFTERNOON|EVENING))([\s,!.:-]+[^\d]+)?$/u',
            $input,
        );
    }

    protected function uomFromSnapshot(mixed $snapshot): ?Uom
    {
        if ($snapshot instanceof Uom) {
            return $snapshot;
        }

        if (! is_array($snapshot) || $snapshot === []) {
            return null;
        }

        $uom = new Uom;
        $uom->forceFill([
            'conversion_factor' => $snapshot['conversion_factor'] ?? 1,
            'full_name' => $snapshot['full_name'] ?? null,
            'small_packaging_label' => $snapshot['small_packaging_label'] ?? null,
            'middle_packaging_label' => $snapshot['middle_packaging_label'] ?? null,
            'middle_factor' => $snapshot['middle_factor'] ?? null,
            'uses_small_packaging' => $snapshot['uses_small_packaging'] ?? true,
            'uom_type' => $snapshot['uom_type'] ?? null,
        ]);

        return $uom;
    }
}
