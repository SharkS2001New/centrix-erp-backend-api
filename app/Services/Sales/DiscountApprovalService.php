<?php

namespace App\Services\Sales;

use App\Models\ActionRequest;
use App\Models\CartLine;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TemporaryCart;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Auth\UserPermissionService;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Fulfillment\AutoTripAssignmentService;
use App\Services\Inventory\SaleStockReservationService;
use App\Services\Kra\SalesVatCalculator;
use App\Services\Notifications\ActionRequestService;
use App\Support\SalesCheckoutSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiscountApprovalService
{
    public function __construct(
        protected UserPermissionService $permissions,
        protected PosLinePricingService $pricing,
        protected ProductCatalogScopeService $catalog,
        protected ProductLineDiscountService $productDiscounts,
        protected SaleStockReservationService $stockReservations,
    ) {}

    public function discountApprovalEnabled(array $salesSettings): bool
    {
        return ! empty($salesSettings['discount_approval_enabled']);
    }

    /** Manual line discounts (direct or via approval workflow). */
    public function allowsManualLineDiscount(array $salesSettings, ?string $orderSource = null): bool
    {
        if ($this->discountApprovalEnabled($salesSettings)) {
            return true;
        }

        if ($orderSource !== null) {
            return SalesCheckoutSettings::allowsManualLineDiscount($salesSettings, $orderSource);
        }

        return ! empty($salesSettings['allow_edit_line_discount'])
            || ! empty($salesSettings['allow_pos_edit_line_discount']);
    }

    /** Order-level discounts — disabled for staff in discount-for-approval mode. */
    public function allowsOrderDiscount(array $salesSettings, ?User $user = null): bool
    {
        if ($user !== null && $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return false;
        }

        return ! empty($salesSettings['enable_order_discount']);
    }

    /** Whether a cart line may carry a discount amount at all. */
    public function allowsLineDiscountAmount(array $salesSettings): bool
    {
        return ! empty($salesSettings['allow_discounts'])
            || $this->discountApprovalEnabled($salesSettings);
    }

    public function thresholdPercent(array $salesSettings): float
    {
        $value = (float) ($salesSettings['discount_approval_threshold_percent'] ?? 10);

        return max(0, min(100, $value));
    }

    public function pendingRequestForCart(TemporaryCart $cart, ?User $user = null): ?ActionRequest
    {
        $user ??= request()->user();
        if (! $user) {
            return null;
        }

        return ActionRequest::query()
            ->where('organization_id', $user->organization_id)
            ->where('type', 'discount')
            ->where('reference_type', 'temporary_cart')
            ->where('reference_id', $cart->id)
            ->where('status', 'pending')
            ->first();
    }

    public function pendingRequestForSale(Sale $sale): ?ActionRequest
    {
        return ActionRequest::query()
            ->where('organization_id', $sale->organization_id)
            ->where('type', 'discount')
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->where('status', 'pending')
            ->first();
    }

    /** @return array<string, mixed>|null */
    public function presentPendingRequest(?ActionRequest $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $payload = $request->payload ?? [];

        return [
            'id' => (int) $request->id,
            'scope' => $payload['scope'] ?? null,
            'discount_amount' => isset($payload['discount_amount'])
                ? round((float) $payload['discount_amount'], 2)
                : null,
            'line_discount_total' => isset($payload['line_discount_total'])
                ? round((float) $payload['line_discount_total'], 2)
                : null,
            'discount_percent' => isset($payload['discount_percent'])
                ? round((float) $payload['discount_percent'], 2)
                : null,
            'reason' => $request->reason,
            'sale_id' => isset($payload['sale_id']) ? (int) $payload['sale_id'] : null,
            'order_num' => isset($payload['order_num']) ? (int) $payload['order_num'] : null,
        ];
    }

    protected function discountActionUrl(TemporaryCart $cart): string
    {
        $channel = strtolower((string) ($cart->channel ?: 'pos'));
        $source = strtolower((string) ($cart->order_source ?: ''));

        if (in_array($channel, ['backend', 'backoffice'], true) || $source === 'backoffice') {
            return '/sales/pos';
        }

        return '/pos';
    }

    protected function saleActionUrl(Sale $sale): string
    {
        $channel = strtolower((string) ($sale->channel ?: 'backend'));

        if ($channel === 'mobile') {
            if ((string) $sale->status === 'editable') {
                return '/mobile/orders?status=editable';
            }

            return '/sales/orders/queues/mobile';
        }

        if ((string) $sale->status === 'editable') {
            return '/sales/orders/queues/editable';
        }

        return '/sales/orders/queues/pending-approval';
    }

    public function saleEditableActionUrl(Sale $sale): string
    {
        $channel = strtolower((string) ($sale->channel ?: 'backend'));

        return $channel === 'mobile'
            ? '/mobile/orders?status=editable'
            : '/sales/orders/queues/editable';
    }

    public function canAutoApproveDiscount(User $user): bool
    {
        return $this->permissions->canGiveDiscountDirectly($user);
    }

    public function discountPercent(float $discountAmount, float $baseAmount): float
    {
        if ($baseAmount <= 0 || $discountAmount <= 0) {
            return 0.0;
        }

        return ($discountAmount / $baseAmount) * 100;
    }

    public function requiresDiscountRequestWorkflow(array $salesSettings, User $user): bool
    {
        return $this->discountApprovalEnabled($salesSettings)
            && ! $this->canAutoApproveDiscount($user);
    }

    public function assertDirectManualDiscountAllowed(
        User $user,
        array $salesSettings,
        float $discountAmount,
        string $field = 'discount_given',
        ?TemporaryCart $cart = null,
    ): void {
        if ($discountAmount <= 0.01) {
            return;
        }

        if ($cart !== null && $this->cartIsOrderEditSession($cart)) {
            return;
        }

        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Manual discounts require manager approval. Submit a discount request with a reason first.',
        ]);
    }

    public function assertCheckoutAllowed(
        TemporaryCart $cart,
        User $user,
        CapabilityGate $gate,
        ?string $checkoutReason = null,
    ): void {
        $salesSettings = $gate->moduleSettings('sales');
        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return;
        }

        if ($this->cartResubmitsRejectedDiscountOrder($cart)) {
            return;
        }

        if (! $this->cartHasManualDiscount($cart)) {
            return;
        }

        $pending = $this->pendingRequestForCart($cart, $user);
        if ($pending !== null && $this->hasValidApprovalReason($pending->reason)) {
            return;
        }

        if ($this->hasValidApprovalReason($checkoutReason)) {
            return;
        }

        throw ValidationException::withMessages([
            'discount_approval_reason' => 'A reason is required for discount approval before checkout.',
        ]);
    }

    public function cartHasManualDiscount(TemporaryCart $cart, ?User $user = null): bool
    {
        $cart->loadMissing('lines');

        if ((float) ($cart->order_discount ?? 0) > 0.01) {
            return true;
        }

        $user ??= User::query()->find($cart->user_id);
        $bypassConfiguredProduct = false;
        if ($user !== null) {
            $gate = app(ErpContext::class)->gateForUser($user);
            $bypassConfiguredProduct = ! $this->requiresDiscountRequestWorkflow(
                $gate->moduleSettings('sales'),
                $user,
            );
        }

        $channel = (string) ($cart->channel ?? 'backend');
        $bypassConfigured = $bypassConfiguredProduct
            && in_array($channel, ['mobile', 'pos'], true);

        foreach ($cart->lines as $line) {
            $discountGiven = (float) ($line->discount_given ?? 0);
            if ($discountGiven <= 0.01) {
                continue;
            }

            if ($bypassConfigured && $this->lineDiscountMatchesConfiguredProduct($cart, $line, $discountGiven)) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function saleHasManualDiscount(Sale $sale, ?User $user = null): bool
    {
        if ((float) ($sale->order_discount ?? 0) > 0.01) {
            return true;
        }

        $sale->loadMissing('items');

        $user ??= User::query()->find($sale->cashier_id);
        $bypassConfiguredProduct = false;
        if ($user !== null) {
            $gate = app(ErpContext::class)->gateForUser($user);
            $bypassConfiguredProduct = ! $this->requiresDiscountRequestWorkflow(
                $gate->moduleSettings('sales'),
                $user,
            );
        }

        $channel = (string) ($sale->channel ?? 'backend');
        $bypassConfigured = $bypassConfiguredProduct
            && in_array($channel, ['mobile', 'pos'], true);

        foreach ($sale->items as $item) {
            $discountGiven = (float) ($item->discount_given ?? 0);
            if ($discountGiven <= 0.01) {
                continue;
            }

            if ($bypassConfigured && $this->saleItemDiscountMatchesConfiguredProduct($sale, $item, $discountGiven)) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function saleRequiresPendingApproval(Sale $sale, User $user, CapabilityGate $gate): bool
    {
        $salesSettings = $gate->moduleSettings('sales');
        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return false;
        }

        return $this->saleHasManualDiscount($sale);
    }

    public function saleWasDiscountRejected(Sale $sale): bool
    {
        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $approval = is_array($meta['discount_approval'] ?? null) ? $meta['discount_approval'] : [];

        return ! empty($approval['rejected_at']);
    }

    public function requiresDiscountResubmitApproval(Sale $sale, User $user, CapabilityGate $gate): bool
    {
        $salesSettings = $gate->moduleSettings('sales');
        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return false;
        }

        if ($this->saleMatchesApproverGuidance($sale)) {
            return false;
        }

        if ((string) $sale->status === 'editable' || $this->saleWasDiscountRejected($sale)) {
            return true;
        }

        return $this->saleHasManualDiscount($sale);
    }

    public function saleMatchesApproverGuidance(Sale $sale): bool
    {
        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $approval = is_array($meta['discount_approval'] ?? null) ? $meta['discount_approval'] : [];
        if (empty($approval['rejected_at'])) {
            return false;
        }

        $guidance = (string) ($approval['rejection_guidance_type'] ?? 'remove_discount');
        if ($guidance === 'advised_amount') {
            return $this->saleMatchesAdvisedDiscount($sale);
        }

        if ($guidance === 'remove_discount') {
            return ! $this->saleHasManualDiscount($sale)
                && abs((float) ($sale->order_discount ?? 0)) <= 0.01;
        }

        return false;
    }

    public function cartMatchesApproverGuidance(TemporaryCart $cart): bool
    {
        if (! $this->cartResubmitsRejectedDiscountOrder($cart)) {
            return false;
        }

        $superseded = Sale::query()->find((int) $cart->superseded_sale_id);
        if ($superseded === null) {
            return false;
        }

        $meta = is_array($superseded->fulfillment_meta) ? $superseded->fulfillment_meta : [];
        $approval = is_array($meta['discount_approval'] ?? null) ? $meta['discount_approval'] : [];
        if (empty($approval['rejected_at'])) {
            return false;
        }

        $guidance = (string) ($approval['rejection_guidance_type'] ?? 'remove_discount');
        if ($guidance === 'advised_amount') {
            $advisedLines = $this->saleAdvisedDiscountLines($superseded);
            if ($advisedLines !== []) {
                return $this->cartLineDiscountsMatchAdvised($cart, $advisedLines);
            }

            $advised = $this->saleAdvisedDiscountAmount($superseded);

            return $advised !== null
                && abs($this->cartTotalDiscountAmount($cart) - $advised) <= 0.01;
        }

        if ($guidance === 'remove_discount') {
            return ! $this->cartHasManualDiscount($cart);
        }

        return false;
    }

    public function cartResubmitsRejectedDiscountOrder(TemporaryCart $cart): bool
    {
        if (! $cart->superseded_sale_id) {
            return false;
        }

        $superseded = Sale::query()->find((int) $cart->superseded_sale_id);

        return $superseded !== null && $this->saleWasDiscountRejected($superseded);
    }

    public function cartIsOrderEditSession(TemporaryCart $cart): bool
    {
        return (int) ($cart->superseded_sale_id ?? 0) > 0
            || (int) ($cart->held_order_num ?? 0) > 0;
    }

    public function advisedDiscountAppliedApprovalReason(): string
    {
        return 'Order has been edited to apply the requested discount. Please check and confirm.';
    }

    public function saleAdvisedDiscountAmount(Sale $sale): ?float
    {
        $lines = $this->saleAdvisedDiscountLines($sale);
        if ($lines !== []) {
            return round((float) collect($lines)->sum('advised_discount'), 2);
        }

        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $approval = is_array($meta['discount_approval'] ?? null) ? $meta['discount_approval'] : [];
        if (($approval['rejection_guidance_type'] ?? '') !== 'advised_amount') {
            return null;
        }
        if (! isset($approval['advised_discount_amount'])) {
            return null;
        }

        return round((float) $approval['advised_discount_amount'], 2);
    }

    /** @return list<array<string, mixed>> */
    public function saleAdvisedDiscountLines(Sale $sale): array
    {
        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $approval = is_array($meta['discount_approval'] ?? null) ? $meta['discount_approval'] : [];
        if (($approval['rejection_guidance_type'] ?? '') !== 'advised_amount') {
            return [];
        }

        $stored = $approval['advised_discount_lines'] ?? null;
        if (! is_array($stored) || $stored === []) {
            return [];
        }

        return $this->normalizeStoredAdvisedDiscountLines($stored);
    }

    /**
     * @param  list<array<string, mixed>>  $sourceLines
     * @param  list<array<string, mixed>>  $inputLines
     * @return list<array<string, mixed>>
     */
    public function buildAdvisedDiscountLines(array $sourceLines, array $inputLines): array
    {
        $byCode = collect($inputLines)->keyBy(fn ($line) => (string) ($line['product_code'] ?? ''));
        $result = [];

        foreach ($sourceLines as $line) {
            $code = (string) ($line['product_code'] ?? '');
            if ($code === '') {
                continue;
            }

            $input = $byCode->get($code);
            if (! is_array($input)) {
                continue;
            }

            $advised = round((float) ($input['advised_discount'] ?? -1), 2);
            if ($advised < 0) {
                continue;
            }

            $result[] = [
                'product_code' => $code,
                'product_name' => (string) ($line['product_name'] ?? $code),
                'unit_price' => round((float) ($line['unit_price'] ?? $line['selling_price'] ?? 0), 2),
                'discount_given' => round((float) ($line['discount_given'] ?? 0), 2),
                'advised_discount' => $advised,
            ];
        }

        return $result;
    }

    /** @param  list<array<string, mixed>>  $lines */
    protected function normalizeStoredAdvisedDiscountLines(array $lines): array
    {
        $normalized = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $code = (string) ($line['product_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $normalized[] = [
                'product_code' => $code,
                'product_name' => (string) ($line['product_name'] ?? $code),
                'unit_price' => round((float) ($line['unit_price'] ?? 0), 2),
                'discount_given' => round((float) ($line['discount_given'] ?? 0), 2),
                'advised_discount' => round((float) ($line['advised_discount'] ?? 0), 2),
            ];
        }

        return $normalized;
    }

    /** @param  list<array<string, mixed>>  $advisedLines */
    protected function saleLineDiscountsMatchAdvised(Sale $sale, array $advisedLines): bool
    {
        $sale->loadMissing('items');
        if (abs((float) ($sale->order_discount ?? 0)) > 0.01) {
            return false;
        }

        $advisedByCode = collect($advisedLines)->keyBy(fn ($line) => (string) ($line['product_code'] ?? ''));

        foreach ($sale->items as $item) {
            $code = (string) $item->product_code;
            $discount = round((float) ($item->discount_given ?? 0), 2);
            $advised = $advisedByCode->get($code);

            if ($advised !== null) {
                if (abs($discount - (float) $advised['advised_discount']) > 0.01) {
                    return false;
                }
                continue;
            }

            if ($discount > 0.01) {
                return false;
            }
        }

        return $advisedByCode->isNotEmpty();
    }

    /** @param  list<array<string, mixed>>  $advisedLines */
    protected function cartLineDiscountsMatchAdvised(TemporaryCart $cart, array $advisedLines): bool
    {
        $cart->loadMissing('lines');
        if (abs((float) ($cart->order_discount ?? 0)) > 0.01) {
            return false;
        }

        $advisedByCode = collect($advisedLines)->keyBy(fn ($line) => (string) ($line['product_code'] ?? ''));

        foreach ($cart->lines as $line) {
            $code = (string) $line->product_code;
            $discount = round((float) ($line->discount_given ?? 0), 2);
            $advised = $advisedByCode->get($code);

            if ($advised !== null) {
                if (abs($discount - (float) $advised['advised_discount']) > 0.01) {
                    return false;
                }
                continue;
            }

            if ($discount > 0.01) {
                return false;
            }
        }

        return $advisedByCode->isNotEmpty();
    }

    public function saleTotalDiscountAmount(Sale $sale): float
    {
        $sale->loadMissing('items');
        $lineDiscount = round((float) $sale->items->sum('discount_given'), 2);
        $orderDiscount = round((float) ($sale->order_discount ?? 0), 2);

        return round($lineDiscount + $orderDiscount, 2);
    }

    public function saleMatchesAdvisedDiscount(Sale $sale, ?Sale $rejectionSource = null): bool
    {
        $source = $rejectionSource ?? $sale;
        $advisedLines = $this->saleAdvisedDiscountLines($source);
        if ($advisedLines !== []) {
            return $this->saleLineDiscountsMatchAdvised($sale, $advisedLines);
        }

        $advised = $this->saleAdvisedDiscountAmount($source);
        if ($advised === null) {
            return false;
        }

        return abs($this->saleTotalDiscountAmount($sale) - $advised) <= 0.01;
    }

    public function cartTotalDiscountAmount(TemporaryCart $cart): float
    {
        $cart->loadMissing('lines');
        $lineDiscount = round((float) $cart->lines->sum('discount_given'), 2);
        $orderDiscount = round((float) ($cart->order_discount ?? 0), 2);

        return round($lineDiscount + $orderDiscount, 2);
    }

    public function cartMatchesAdvisedDiscount(TemporaryCart $cart): bool
    {
        return $this->cartMatchesApproverGuidance($cart);
    }

    /** @return array{reason: string, message: string, advised_discount_applied: bool} */
    protected function buildResubmitApprovalPresentation(
        Sale $sale,
        User $user,
        bool $resubmit,
        ?Sale $rejectionSource,
        string $defaultReason,
        string $defaultMessage,
        ?TemporaryCart $cart = null,
    ): array {
        $advisedApplied = $resubmit
            && $rejectionSource !== null
            && ($this->saleMatchesAdvisedDiscount($sale, $rejectionSource)
                || ($cart !== null && $this->cartMatchesAdvisedDiscount($cart)));

        if (! $advisedApplied) {
            return [
                'reason' => $defaultReason,
                'message' => $defaultMessage,
                'advised_discount_applied' => false,
            ];
        }

        $requesterName = $user->full_name ?: $user->username;

        return [
            'reason' => $this->advisedDiscountAppliedApprovalReason(),
            'message' => "{$requesterName} edited order #{$sale->order_num} to apply the requested discount. Please check and confirm.",
            'advised_discount_applied' => true,
        ];
    }

    public function resubmitSaleForApproval(
        Sale $sale,
        User $user,
        CapabilityGate $gate,
        ?string $reason = null,
        bool $fromEditableSave = false,
    ): ?ActionRequest {
        $salesSettings = $gate->moduleSettings('sales');
        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return null;
        }

        if (! $fromEditableSave && ! $this->requiresDiscountResubmitApproval($sale, $user, $gate)) {
            return null;
        }

        $approvalReason = trim((string) $reason);
        if (! $this->hasValidApprovalReason($approvalReason)) {
            $lastRequest = ActionRequest::query()
                ->where('organization_id', $sale->organization_id)
                ->where('reference_type', 'sale')
                ->where('reference_id', $sale->id)
                ->where('type', 'discount')
                ->orderByDesc('id')
                ->first();
            $approvalReason = trim((string) ($lastRequest?->reason ?? ''));
        }
        if (! $this->hasValidApprovalReason($approvalReason)) {
            $approvalReason = 'Resubmitted after discount revision';
        }

        $lineDiscountTotal = round((float) $sale->items->sum('discount_given'), 2);
        $orderDiscount = round((float) ($sale->order_discount ?? 0), 2);
        $totals = [
            'line_discount_total' => $lineDiscountTotal,
            'order_discount' => $orderDiscount,
            'total_discount' => round($lineDiscountTotal + $orderDiscount, 2),
        ];

        $requesterName = $user->full_name ?: $user->username;
        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $approvalMeta = is_array($meta['discount_approval'] ?? null) ? $meta['discount_approval'] : [];
        $advisedApplied = ! empty($approvalMeta['advised_discount_applied'])
            || $this->saleMatchesAdvisedDiscount($sale);

        if ($advisedApplied) {
            $presentation = [
                'reason' => $this->advisedDiscountAppliedApprovalReason(),
                'message' => "{$requesterName} edited order #{$sale->order_num} to apply the requested discount. Please check and confirm.",
                'advised_discount_applied' => true,
            ];
        } else {
            $presentation = [
                'reason' => $approvalReason,
                'message' => "{$requesterName} resubmitted order #{$sale->order_num} for discount approval.",
                'advised_discount_applied' => false,
            ];
        }

        $request = app(ActionRequestService::class)->requestApproval($user, [
            'type' => 'discount',
            'module' => 'sales',
            'reference_type' => 'sale',
            'reference_id' => (int) $sale->id,
            'approver_permission' => 'admin.discount_approvals.approve',
            'title' => 'Discount approval required',
            'message' => $presentation['message'],
            'reason' => $presentation['reason'],
            'severity' => 'warning',
            'action_url' => $this->saleActionUrl($sale),
            'allow_duplicate_reference' => true,
            'payload' => [
                'scope' => 'order',
                'discount_amount' => $totals['total_discount'],
                'line_discount_total' => $totals['line_discount_total'],
                'sale_id' => (int) $sale->id,
                'order_num' => (int) $sale->order_num,
                'order_discount' => $totals['order_discount'],
                'lines' => $this->approvalLinesPayloadFromSale($sale),
                'channel' => (string) ($sale->channel ?? 'mobile'),
                'order_source' => (string) ($sale->order_source ?? ''),
                'advised_discount_applied' => $presentation['advised_discount_applied'],
                'discount_revision_submitted' => $fromEditableSave,
            ],
        ]);

        $meta['discount_approval'] = array_merge($approvalMeta, [
            'action_request_id' => (int) $request->id,
            'scope' => 'order',
            'discount_amount' => $totals['total_discount'],
            'discount_percent' => null,
            'advised_discount_applied' => $presentation['advised_discount_applied'],
        ]);
        $sale->update(['fulfillment_meta' => $meta]);

        return $request;
    }

    /** @return list<array<string, mixed>> */
    public function approvalLinesPayload(TemporaryCart $cart): array
    {
        $cart->loadMissing('lines');

        return $cart->lines->map(function (CartLine $line) {
            $product = Product::query()->find($line->product_code);
            $isRetail = $product
                ? (bool) $product->sell_on_retail && (bool) $line->on_wholesale_retail
                : (bool) $line->on_wholesale_retail;

            return $this->presentApprovalLinePayload(
                (string) $line->product_code,
                (string) ($line->product_name ?: $line->product_code),
                (float) $line->quantity,
                (float) $line->amount,
                (float) $line->unit_price,
                (float) ($line->discount_given ?? 0),
                $line->uom,
                $isRetail,
                $product,
            );
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function approvalLinesPayloadFromSale(Sale $sale): array
    {
        $sale->loadMissing(['items.product']);

        return $sale->items->map(function ($item) {
            $product = $item->product;
            $isRetail = (bool) $item->on_wholesale_retail;

            return $this->presentApprovalLinePayload(
                (string) $item->product_code,
                (string) ($product?->product_name ?? $item->product_code),
                (float) ($item->quantity ?? 0),
                (float) ($item->amount ?? 0),
                (float) ($item->selling_price ?? 0),
                (float) ($item->discount_given ?? 0),
                $item->uom,
                $isRetail,
                $product,
            );
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentApprovalLinePayload(
        string $productCode,
        string $productName,
        float $baseQty,
        float $amount,
        float $unitPriceStored,
        float $discountGiven,
        ?string $uom,
        bool $isRetail,
        ?Product $product,
    ): array {
        $display = app(SaleLineQuantityDisplayService::class);
        $displayUnitPrice = $product
            ? $display->displayUnitPrice($baseQty, $amount, $product, $isRetail, $discountGiven, $unitPriceStored)
            : $unitPriceStored;
        $qtyDisp = $product
            ? $display->formatLineQtyDisplay($baseQty, $product, $isRetail, $uom)
            : trim($baseQty.' '.trim((string) ($uom ?? '')));

        return [
            'product_code' => $productCode,
            'product_name' => $productName,
            'unit_price' => round($displayUnitPrice, 2),
            'selling_price' => round($unitPriceStored, 2),
            'discount_given' => round($discountGiven, 2),
            'amount' => round($amount, 2),
            'quantity' => $baseQty,
            'display_quantity' => $product
                ? $display->entryQtyFromBase($baseQty, $product, $isRetail)
                : $baseQty,
            'qty_disp' => $qtyDisp,
            'uom' => $uom,
            'on_wholesale_retail' => $isRetail ? 1 : 0,
        ];
    }

    public function checkoutRequiresPendingApproval(TemporaryCart $cart, User $user, CapabilityGate $gate): bool
    {
        $salesSettings = $gate->moduleSettings('sales');
        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return false;
        }

        if ($this->cartMatchesApproverGuidance($cart)) {
            return false;
        }

        if ($this->cartHasManualDiscount($cart)) {
            return true;
        }

        if ($this->cartResubmitsRejectedDiscountOrder($cart)) {
            return true;
        }

        return $this->pendingRequestForCart($cart, $user) !== null;
    }

    /** @return array{applied: bool, action_request?: ActionRequest, cart: TemporaryCart} */
    public function applyOrRequest(
        User $user,
        TemporaryCart $cart,
        CapabilityGate $gate,
        array $data,
    ): array {
        $salesSettings = $gate->moduleSettings('sales');
        $scope = (string) ($data['scope'] ?? '');
        $discountAmount = max(0, (float) ($data['discount_amount'] ?? 0));
        $reason = trim((string) ($data['reason'] ?? ''));

        if (! in_array($scope, ['line', 'order'], true)) {
            throw ValidationException::withMessages(['scope' => 'Scope must be line or order.']);
        }

        if ($scope === 'line' && empty($data['line_ref'])) {
            throw ValidationException::withMessages(['line_ref' => 'Line reference is required for line discounts.']);
        }

        if ($scope === 'order' && ! $this->allowsOrderDiscount($salesSettings, $user)) {
            throw ValidationException::withMessages(['scope' => 'Order discount is not enabled.']);
        }

        if ($scope === 'line' && ! $this->allowsManualLineDiscount($salesSettings, $cart->order_source)) {
            throw ValidationException::withMessages(['scope' => 'Manual line discounts are not enabled.']);
        }

        $needsApproval = $this->discountApprovalEnabled($salesSettings)
            && $discountAmount > 0.01
            && ! $this->canAutoApproveDiscount($user);

        $deferApproval = ! empty($data['defer_approval']);

        $existingPending = $this->pendingRequestForCart($cart, $user);
        $reasonRequired = $needsApproval && ! $deferApproval;

        if ($reasonRequired) {
            $reason = $this->resolveOrderApprovalReason($existingPending, $reason);
        }

        if (! $needsApproval) {
            return [
                'applied' => true,
                'cart' => $this->applyDiscount($user, $cart, $gate, $scope, $discountAmount, (string) ($data['line_ref'] ?? '')),
            ];
        }

        if ($deferApproval) {
            return [
                'applied' => true,
                'deferred_approval' => true,
                'cart' => $this->applyDiscount(
                    $user,
                    $cart,
                    $gate,
                    $scope,
                    $discountAmount,
                    (string) ($data['line_ref'] ?? ''),
                )->fresh('lines'),
            ];
        }

        $cart = $this->applyDiscount($user, $cart, $gate, $scope, $discountAmount, (string) ($data['line_ref'] ?? ''));

        $actionRequest = $this->upsertPendingCartDiscountRequest(
            $user,
            $cart->fresh('lines'),
            $reason,
            $existingPending,
        );

        return [
            'applied' => false,
            'action_request' => $actionRequest,
            'cart' => $cart->fresh('lines'),
        ];
    }

    protected function hasValidApprovalReason(?string $reason): bool
    {
        return strlen(trim((string) $reason)) >= 3;
    }

    protected function resolveOrderApprovalReason(?ActionRequest $existingPending, string $incoming): string
    {
        $existing = trim((string) ($existingPending?->reason ?? ''));
        if ($this->hasValidApprovalReason($existing)) {
            return $existing;
        }

        $incoming = trim($incoming);
        if ($this->hasValidApprovalReason($incoming)) {
            return $incoming;
        }

        throw ValidationException::withMessages([
            'reason' => 'A reason is required once per order when applying discounts with approval enabled.',
        ]);
    }

    /** @return array{line_discount_total: float, order_discount: float, total_discount: float, base_amount: float} */
    protected function cartApprovalTotals(TemporaryCart $cart): array
    {
        $cart->loadMissing('lines');

        $lineDiscount = 0.0;
        $lineGross = 0.0;
        foreach ($cart->lines as $line) {
            $lineDiscount += (float) ($line->discount_given ?? 0);
            $lineGross += (float) ($line->amount ?? 0) + (float) ($line->discount_given ?? 0);
        }

        $orderDiscount = (float) ($cart->order_discount ?? 0);

        return [
            'line_discount_total' => round($lineDiscount, 2),
            'order_discount' => round($orderDiscount, 2),
            'total_discount' => round($lineDiscount + $orderDiscount, 2),
            'base_amount' => round(max(0, $lineGross), 2),
        ];
    }

    protected function upsertPendingCartDiscountRequest(
        User $user,
        TemporaryCart $cart,
        string $reason,
        ?ActionRequest $existingPending = null,
    ): ActionRequest {
        $existingPending ??= $this->pendingRequestForCart($cart, $user);
        $totals = $this->cartApprovalTotals($cart);
        $percent = $this->discountPercent($totals['total_discount'], max(0.01, $totals['base_amount']));
        $requesterName = $user->full_name ?: $user->username;
        $percentLabel = number_format($percent, 1).'%';
        $title = 'Discount approval required';
        $message = "{$requesterName} requested order discounts totalling {$percentLabel} on cart #{$cart->id}.";

        $payload = [
            'scope' => 'order',
            'line_ref' => null,
            'discount_amount' => $totals['total_discount'],
            'line_discount_total' => $totals['line_discount_total'],
            'discount_percent' => round($percent, 2),
            'action_url' => $this->discountActionUrl($cart),
            'cart_id' => (int) $cart->id,
            'channel' => (string) ($cart->channel ?? 'pos'),
            'order_source' => (string) ($cart->order_source ?? ''),
            'order_discount' => $totals['order_discount'],
            'lines' => $this->approvalLinesPayload($cart),
        ];

        if ($existingPending !== null) {
            $existingPending->update([
                'reason' => $reason,
                'title' => $title,
                'message' => $message,
                'payload' => $payload,
            ]);

            return $existingPending->fresh();
        }

        return app(ActionRequestService::class)->requestApproval($user, [
            'type' => 'discount',
            'module' => 'sales',
            'reference_type' => 'temporary_cart',
            'reference_id' => (int) $cart->id,
            'approver_permission' => 'admin.discount_approvals.approve',
            'title' => $title,
            'message' => $message,
            'reason' => $reason,
            'severity' => 'warning',
            'action_url' => $this->discountActionUrl($cart),
            'payload' => $payload,
        ]);
    }

    public function attachCheckoutToSale(
        Sale $sale,
        TemporaryCart $cart,
        User $user,
        ?string $checkoutReason = null,
    ): void {
        $resubmit = $this->cartResubmitsRejectedDiscountOrder($cart);
        $pending = $resubmit
            ? null
            : $this->pendingRequestForCart($cart, $user);
        if ($pending === null) {
            $pending = $this->ensureSaleDiscountApprovalRequest($sale, $cart, $user, $checkoutReason);
        }
        if ($pending === null) {
            throw ValidationException::withMessages([
                'discount_approval' => 'Could not submit this order for discount approval.',
            ]);
        }

        $payload = $pending->payload ?? [];
        $payload['sale_id'] = (int) $sale->id;
        $payload['order_num'] = (int) $sale->order_num;
        $payload['action_url'] = $this->saleActionUrl($sale);
        $payload['order_discount'] = round((float) ($sale->order_discount ?? 0), 2);
        $payload['lines'] = $this->approvalLinesPayloadFromSale($sale);

        $pending->update([
            'reference_type' => 'sale',
            'reference_id' => (int) $sale->id,
            'action_url' => $this->saleActionUrl($sale),
            'message' => str_replace(
                "cart #{$cart->id}",
                "order #{$sale->order_num}",
                (string) $pending->message,
            ),
            'payload' => $payload,
        ]);

        $meta = $sale->fulfillment_meta ?? [];
        $meta['discount_approval'] = [
            'action_request_id' => (int) $pending->id,
            'scope' => $payload['scope'] ?? null,
            'discount_amount' => $payload['discount_amount'] ?? null,
            'discount_percent' => $payload['discount_percent'] ?? null,
            'advised_discount_applied' => ! empty($payload['advised_discount_applied']),
        ];
        $sale->update(['fulfillment_meta' => $meta]);
    }

    protected function ensureSaleDiscountApprovalRequest(
        Sale $sale,
        TemporaryCart $cart,
        User $user,
        ?string $checkoutReason = null,
    ): ?ActionRequest {
        if ($sale->status !== 'pending_approval') {
            return null;
        }

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($user);
        $salesSettings = $gate->moduleSettings('sales');
        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return null;
        }

        $resubmit = $this->cartResubmitsRejectedDiscountOrder($cart);

        if (! $resubmit && ! $this->cartHasManualDiscount($cart) && (float) ($sale->order_discount ?? 0) <= 0.01) {
            $sale->loadMissing('items');
            $hasLineDiscount = $sale->items->contains(
                fn ($item) => (float) ($item->discount_given ?? 0) > 0.01,
            );
            if (! $hasLineDiscount) {
                return null;
            }
        }

        $lineDiscountTotal = (float) CartLine::query()->where('cart_id', $cart->id)->sum('discount_given');
        $orderDiscount = max((float) ($cart->order_discount ?? 0), (float) ($sale->order_discount ?? 0));
        $totals = [
            'line_discount_total' => round($lineDiscountTotal, 2),
            'order_discount' => round($orderDiscount, 2),
            'total_discount' => round($lineDiscountTotal + $orderDiscount, 2),
        ];

        $requesterName = $user->full_name ?: $user->username;
        $existingCartPending = $this->pendingRequestForCart($cart, $user);
        $approvalReason = trim((string) ($existingCartPending?->reason ?? ''));
        if (! $this->hasValidApprovalReason($approvalReason)) {
            $approvalReason = trim((string) ($checkoutReason ?? ''));
        }
        if (! $this->hasValidApprovalReason($approvalReason)) {
            $approvalReason = $resubmit
                ? 'Resubmitted after discount revision'
                : '';
        }
        if (! $resubmit && ! $this->hasValidApprovalReason($approvalReason)) {
            return null;
        }

        $rejectionSource = $resubmit && $cart->superseded_sale_id
            ? Sale::query()->find((int) $cart->superseded_sale_id)
            : null;

        $defaultMessage = $resubmit
            ? "{$requesterName} resubmitted order #{$sale->order_num} for approval."
            : "{$requesterName} requested order discounts on order #{$sale->order_num}.";

        $presentation = $resubmit
            ? $this->buildResubmitApprovalPresentation(
                $sale,
                $user,
                true,
                $rejectionSource,
                $approvalReason,
                $defaultMessage,
                $cart,
            )
            : [
                'reason' => $approvalReason,
                'message' => $defaultMessage,
                'advised_discount_applied' => false,
            ];

        if (! $presentation['advised_discount_applied'] && ! $this->hasValidApprovalReason($presentation['reason'])) {
            return null;
        }

        return app(ActionRequestService::class)->requestApproval($user, [
            'type' => 'discount',
            'module' => 'sales',
            'reference_type' => 'sale',
            'reference_id' => (int) $sale->id,
            'approver_permission' => 'admin.discount_approvals.approve',
            'title' => 'Discount approval required',
            'message' => $presentation['message'],
            'reason' => $presentation['reason'],
            'severity' => 'warning',
            'action_url' => $this->saleActionUrl($sale),
            'allow_duplicate_reference' => true,
            'payload' => [
                'scope' => 'order',
                'discount_amount' => $totals['total_discount'],
                'line_discount_total' => $totals['line_discount_total'],
                'sale_id' => (int) $sale->id,
                'order_num' => (int) $sale->order_num,
                'order_discount' => $totals['order_discount'],
                'lines' => $this->approvalLinesPayloadFromSale($sale),
                'channel' => (string) ($sale->channel ?? 'mobile'),
                'order_source' => (string) ($sale->order_source ?? ''),
                'advised_discount_applied' => $presentation['advised_discount_applied'],
                'discount_revision_submitted' => $resubmit && (int) ($cart->superseded_sale_id ?? 0) > 0,
            ],
        ]);
    }

    public function approveFromActionRequest(ActionRequest $request, User $user): void
    {
        if ($request->reference_type === 'sale') {
            $this->approveSale((int) $request->reference_id, $user);

            return;
        }

        $this->applyFromActionRequest($request);
    }

    public function rejectFromActionRequest(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        if ($request->reference_type !== 'sale') {
            return;
        }

        $sale = Sale::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        if ($sale->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => 'This order is no longer awaiting discount approval.',
            ]);
        }

        $guidance = (string) ($options['discount_guidance'] ?? 'remove_discount');
        if (! in_array($guidance, ['remove_discount', 'advised_amount'], true)) {
            throw ValidationException::withMessages([
                'discount_guidance' => 'Choose whether to remove the discount or advise an amount.',
            ]);
        }

        $advisedAmount = null;
        $advisedLines = [];
        if ($guidance === 'advised_amount') {
            $sourceLines = is_array($request->payload['lines'] ?? null) ? $request->payload['lines'] : [];
            $inputLines = is_array($options['advised_discount_lines'] ?? null) ? $options['advised_discount_lines'] : [];
            $advisedLines = $this->buildAdvisedDiscountLines($sourceLines, $inputLines);

            if ($advisedLines === []) {
                $legacyAmount = round((float) ($options['advised_discount_amount'] ?? -1), 2);
                if ($legacyAmount >= 0 && $sourceLines !== []) {
                    $firstDiscounted = collect($sourceLines)->first(
                        fn ($line) => round((float) ($line['discount_given'] ?? 0), 2) > 0.01,
                    ) ?? $sourceLines[0];
                    $advisedLines = $this->buildAdvisedDiscountLines($sourceLines, [[
                        'product_code' => (string) ($firstDiscounted['product_code'] ?? ''),
                        'advised_discount' => $legacyAmount,
                    ]]);
                }
            }

            if ($advisedLines === []) {
                throw ValidationException::withMessages([
                    'advised_discount_lines' => 'Enter the advised discount for each item.',
                ]);
            }

            $advisedAmount = round((float) collect($advisedLines)->sum('advised_discount'), 2);
        }

        $meta = $sale->fulfillment_meta ?? [];
        $meta['discount_approval'] = array_merge($meta['discount_approval'] ?? [], [
            'rejected_at' => now()->toIso8601String(),
            'rejected_by' => (int) $user->id,
            'rejection_reason' => $reason,
            'rejection_guidance_type' => $guidance,
            'advised_discount_amount' => $advisedAmount,
            'advised_discount_lines' => $advisedLines !== [] ? $advisedLines : null,
        ]);

        $sale->update([
            'status' => 'editable',
            'fulfillment_meta' => $meta,
        ]);
    }

    public function approveSale(int $saleId, User $user): void
    {
        $sale = Sale::query()
            ->where('organization_id', $user->organization_id)
            ->with(['items', 'payments.paymentMethod'])
            ->findOrFail($saleId);

        if ($sale->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => 'This order is no longer awaiting discount approval.',
            ]);
        }

        app(\App\Http\Controllers\Api\V1\Operations\OrderWorkflowController::class)
            ->transitionSaleForUser($sale, 'booked', $user);

        $sale = $sale->fresh(['items', 'payments.paymentMethod']);

        app(CustomerInvoiceService::class)->ensureForSale(
            $sale,
            $user,
            (float) $sale->order_total,
            (float) $sale->amount_paid,
        );

        app(AutoTripAssignmentService::class)->tryAssignSale($sale, $user);

        $gate = app(ErpContext::class)->gateForUser($user);
        $this->stockReservations->reserveIfNeeded($sale->fresh(['items']), $user, $gate);
    }

    public function applyFromActionRequest(ActionRequest $request): void
    {
        if ($request->reference_type === 'sale') {
            $this->approveSale((int) $request->reference_id, User::query()->findOrFail((int) $request->requested_by));

            return;
        }

        $payload = $request->payload ?? [];
        $cart = TemporaryCart::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        $user = User::query()->findOrFail((int) $request->requested_by);
        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($user);

        $this->applyDiscount(
            $user,
            $cart,
            $gate,
            (string) ($payload['scope'] ?? 'order'),
            max(0, (float) ($payload['discount_amount'] ?? 0)),
            (string) ($payload['line_ref'] ?? ''),
        );
    }

    protected function applyDiscount(
        User $user,
        TemporaryCart $cart,
        CapabilityGate $gate,
        string $scope,
        float $discountAmount,
        string $lineRef,
    ): TemporaryCart {
        return DB::transaction(function () use ($user, $cart, $gate, $scope, $discountAmount, $lineRef) {
            if ($scope === 'order') {
                $cart->update(['order_discount' => $discountAmount]);
                $cart->increment('update_no');

                return $cart->fresh('lines');
            }

            $row = $this->findCartLineByRef($cart, $lineRef);
            $product = $this->findProductForCart($cart, (string) $row->product_code, $user);
            $qty = (float) $row->quantity;
            $isRetail = $this->isRetailLine($product, (bool) $row->on_wholesale_retail);
            $salesSettings = $gate->moduleSettings('sales');
            $routeId = app(MobileRouteMarkupCheckoutService::class)->routeIdForCartPricing(
                $cart,
                $salesSettings,
            );

            $grossBeforeDiscount = $this->pricing->lineTotalBeforeDiscount(
                $product,
                $qty,
                $isRetail,
                $routeId,
                (int) $user->organization_id,
            );
            $amount = round(max(0, $grossBeforeDiscount - $discountAmount), 2);
            $unitPrice = $qty > 0 ? round($amount / $qty, 4) : 0.0;

            $product->loadMissing('vat');
            $productVat = SalesVatCalculator::vatFromInclusiveGross(
                max(0, $amount),
                SalesVatCalculator::vatRateFromProduct($product),
            );

            $row->update([
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'discount_given' => $discountAmount,
                'product_vat' => $productVat,
            ]);

            $cart->increment('update_no');

            return $cart->fresh('lines');
        });
    }

    protected function resolveDiscountBase(
        TemporaryCart $cart,
        string $scope,
        string $lineRef,
        User $user,
        CapabilityGate $gate,
    ): float {
        if ($scope === 'order') {
            return (float) CartLine::query()->where('cart_id', $cart->id)->sum('amount');
        }

        $row = $this->findCartLineByRef($cart, $lineRef);
        $product = $this->findProductForCart($cart, (string) $row->product_code, $user);
        $qty = (float) $row->quantity;
        $isRetail = $this->isRetailLine($product, (bool) $row->on_wholesale_retail);
        $salesSettings = $gate->moduleSettings('sales');

        [, $amountWithoutDiscount] = $this->pricing->resolveLineAmounts(
            $product,
            $qty,
            $isRetail,
            0,
            app(MobileRouteMarkupCheckoutService::class)->routeIdForCartPricing(
                $cart,
                $salesSettings,
            ),
            (float) $row->unit_price,
            SalesCheckoutSettings::allowsEditableUnitPrice($salesSettings, $cart->order_source),
        );

        return $amountWithoutDiscount;
    }

    protected function findCartLineByRef(TemporaryCart $cart, string $lineRef): CartLine
    {
        $lineRef = trim($lineRef);
        $query = CartLine::query()->where('cart_id', $cart->id);

        $line = (clone $query)->where('update_code', $lineRef)->first();
        if ($line) {
            return $line;
        }

        if (ctype_digit($lineRef)) {
            return $query->where('id', (int) $lineRef)->firstOrFail();
        }

        abort(404, 'Cart line not found.');
    }

    protected function findProductForCart(TemporaryCart $cart, string $productCode, User $user): Product
    {
        $orgId = (int) $user->organization_id;
        $branchId = (int) ($cart->branch_id ?: $user->branch_id ?: 0);

        return $this->catalog->findAccessibleProduct(trim($productCode), $orgId, $branchId);
    }

    protected function isRetailLine(Product $product, bool $onWholesaleRetailFlag): bool
    {
        return (bool) $product->sell_on_retail && $onWholesaleRetailFlag;
    }

    protected function lineDiscountMatchesConfiguredProduct(
        TemporaryCart $cart,
        CartLine $line,
        float $discountGiven,
    ): bool {
        $user = $cart->relationLoaded('user') ? $cart->user : User::query()->find($cart->user_id);
        if ($user === null) {
            return false;
        }

        try {
            $product = $this->findProductForCart($cart, (string) $line->product_code, $user);
        } catch (\Throwable) {
            return false;
        }

        if (! $this->productDiscounts->productHasConfiguredDiscount($product)) {
            return false;
        }

        $product->loadMissing('unit');
        $isRetail = $this->isRetailLine($product, (bool) $line->on_wholesale_retail);
        $salesSettings = app(ErpContext::class)->gateForUser($user)->moduleSettings('sales');
        $routeId = app(MobileRouteMarkupCheckoutService::class)->routeIdForCartPricing($cart, $salesSettings);
        $beforeDiscount = $this->pricing->lineTotalBeforeDiscount(
            $product,
            (float) $line->quantity,
            $isRetail,
            $routeId,
        );
        $conversion = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        $packQty = $conversion > 1 ? (float) $line->quantity / $conversion : (float) $line->quantity;
        $configured = $this->productDiscounts->computeProductLineDiscount($product, $beforeDiscount, $packQty);

        return abs($discountGiven - $configured) <= 0.02;
    }

    protected function saleItemDiscountMatchesConfiguredProduct(
        Sale $sale,
        $item,
        float $discountGiven,
    ): bool {
        try {
            $product = $this->catalog->findAccessibleProduct(
                (string) $item->product_code,
                (int) $sale->organization_id,
                (int) ($sale->branch_id ?: 0),
            );
        } catch (\Throwable) {
            return false;
        }

        if (! $this->productDiscounts->productHasConfiguredDiscount($product)) {
            return false;
        }

        $user = $sale->relationLoaded('user') ? $sale->user : User::query()->find($sale->user_id);
        if ($user === null) {
            return false;
        }

        $product->loadMissing('unit');
        $isRetail = $this->isRetailLine($product, (bool) $item->on_wholesale_retail);
        $salesSettings = app(ErpContext::class)->gateForUser($user)->moduleSettings('sales');
        $routeId = $sale->route_id ? (int) $sale->route_id : null;
        $beforeDiscount = $this->pricing->lineTotalBeforeDiscount(
            $product,
            (float) $item->quantity,
            $isRetail,
            $routeId,
        );
        $conversion = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        $packQty = $conversion > 1 ? (float) $item->quantity / $conversion : (float) $item->quantity;
        $configured = $this->productDiscounts->computeProductLineDiscount($product, $beforeDiscount, $packQty);

        return abs($discountGiven - $configured) <= 0.02;
    }
}
