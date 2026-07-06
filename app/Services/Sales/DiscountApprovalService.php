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
use App\Services\Erp\OrderWorkflowService;
use App\Services\Fulfillment\AutoTripAssignmentService;
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

    /** Order-level discounts (direct or via approval workflow). */
    public function allowsOrderDiscount(array $salesSettings): bool
    {
        return ! empty($salesSettings['enable_order_discount'])
            || $this->discountApprovalEnabled($salesSettings);
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
            return '/sales/orders/queues/mobile';
        }

        return '/sales/orders/queues/pending-approval';
    }

    public function canAutoApproveDiscount(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'sales.manage')
            || $this->permissions->hasPermission($user, 'sales.orders.approve');
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
    ): void {
        if ($discountAmount <= 0.01) {
            return;
        }

        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Manual discounts require manager approval. Submit a discount request with a reason first.',
        ]);
    }

    public function assertCheckoutAllowed(TemporaryCart $cart, User $user, CapabilityGate $gate): void
    {
        $salesSettings = $gate->moduleSettings('sales');
        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return;
        }

        if (! $this->cartHasManualDiscount($cart)) {
            return;
        }

        $pending = $this->pendingRequestForCart($cart, $user);
        if ($pending === null) {
            throw ValidationException::withMessages([
                'cart' => 'This cart has discounts that require manager approval. Submit a discount request with a reason before checkout.',
            ]);
        }

        if (! $this->hasValidApprovalReason($pending->reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required for discount approval before checkout.',
            ]);
        }
    }

    public function cartHasManualDiscount(TemporaryCart $cart): bool
    {
        $cart->loadMissing('lines');

        if ((float) ($cart->order_discount ?? 0) > 0.01) {
            return true;
        }

        foreach ($cart->lines as $line) {
            if ((float) ($line->discount_given ?? 0) > 0.01) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public function approvalLinesPayload(TemporaryCart $cart): array
    {
        $cart->loadMissing('lines');

        return $cart->lines->map(fn (CartLine $line) => [
            'product_code' => (string) $line->product_code,
            'product_name' => (string) ($line->product_name ?: $line->product_code),
            'unit_price' => round((float) $line->unit_price, 2),
            'discount_given' => round((float) ($line->discount_given ?? 0), 2),
            'amount' => round((float) $line->amount, 2),
            'quantity' => (float) $line->quantity,
            'uom' => $line->uom,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function approvalLinesPayloadFromSale(Sale $sale): array
    {
        $sale->loadMissing(['items.product']);

        return $sale->items->map(fn ($item) => [
            'product_code' => (string) $item->product_code,
            'product_name' => (string) ($item->product?->product_name ?? $item->product_code),
            'unit_price' => round((float) ($item->selling_price ?? 0), 2),
            'discount_given' => round((float) ($item->discount_given ?? 0), 2),
            'amount' => round((float) ($item->amount ?? 0), 2),
            'quantity' => (float) ($item->quantity ?? 0),
            'uom' => $item->uom,
        ])->values()->all();
    }

    public function checkoutRequiresPendingApproval(TemporaryCart $cart, User $user, CapabilityGate $gate): bool
    {
        $salesSettings = $gate->moduleSettings('sales');
        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return false;
        }

        if ($this->cartHasManualDiscount($cart)) {
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

        if ($scope === 'order' && ! $this->allowsOrderDiscount($salesSettings)) {
            throw ValidationException::withMessages(['scope' => 'Order discount is not enabled.']);
        }

        if ($scope === 'line' && ! $this->allowsManualLineDiscount($salesSettings, $cart->order_source)) {
            throw ValidationException::withMessages(['scope' => 'Manual line discounts are not enabled.']);
        }

        $needsApproval = $this->discountApprovalEnabled($salesSettings)
            && $discountAmount > 0.01
            && ! $this->canAutoApproveDiscount($user);

        $existingPending = $this->pendingRequestForCart($cart, $user);
        $reasonRequired = $this->discountApprovalEnabled($salesSettings) && $discountAmount > 0.01;

        if ($reasonRequired) {
            $reason = $this->resolveOrderApprovalReason($existingPending, $reason);
        }

        if (! $needsApproval) {
            return [
                'applied' => true,
                'cart' => $this->applyDiscount($user, $cart, $gate, $scope, $discountAmount, (string) ($data['line_ref'] ?? '')),
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
            'approver_permission' => 'sales.orders.approve',
            'title' => $title,
            'message' => $message,
            'reason' => $reason,
            'severity' => 'warning',
            'action_url' => $this->discountActionUrl($cart),
            'payload' => $payload,
        ]);
    }

    public function attachCheckoutToSale(Sale $sale, TemporaryCart $cart, User $user): void
    {
        $pending = $this->pendingRequestForCart($cart, $user);
        if ($pending === null) {
            $pending = $this->ensureSaleDiscountApprovalRequest($sale, $cart, $user);
        }
        if ($pending === null) {
            return;
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
        ];
        $sale->update(['fulfillment_meta' => $meta]);
    }

    protected function ensureSaleDiscountApprovalRequest(
        Sale $sale,
        TemporaryCart $cart,
        User $user,
    ): ?ActionRequest {
        if ($sale->status !== 'pending_approval') {
            return null;
        }

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($user);
        $salesSettings = $gate->moduleSettings('sales');
        if (! $this->requiresDiscountRequestWorkflow($salesSettings, $user)) {
            return null;
        }

        if (! $this->cartHasManualDiscount($cart) && (float) ($sale->order_discount ?? 0) <= 0.01) {
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
            return null;
        }

        return app(ActionRequestService::class)->requestApproval($user, [
            'type' => 'discount',
            'module' => 'sales',
            'reference_type' => 'sale',
            'reference_id' => (int) $sale->id,
            'approver_permission' => 'sales.orders.approve',
            'title' => 'Discount approval required',
            'message' => "{$requesterName} requested order discounts on order #{$sale->order_num}.",
            'reason' => $approvalReason,
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

    public function rejectFromActionRequest(ActionRequest $request, User $user, ?string $reason): void
    {
        if ($request->reference_type !== 'sale') {
            return;
        }

        $sale = Sale::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        if ($sale->status !== 'pending_approval') {
            return;
        }

        $meta = $sale->fulfillment_meta ?? [];
        $meta['discount_approval'] = array_merge($meta['discount_approval'] ?? [], [
            'rejected_at' => now()->toIso8601String(),
            'rejected_by' => (int) $user->id,
            'rejection_reason' => $reason,
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
            return;
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

            [$unitPrice, $amount] = $this->pricing->resolveLineAmounts(
                $product,
                $qty,
                $isRetail,
                $discountAmount,
                app(MobileRouteMarkupCheckoutService::class)->routeIdForCartPricing(
                    $cart,
                    $salesSettings,
                ),
                (float) $row->unit_price,
                SalesCheckoutSettings::allowsEditableUnitPrice($salesSettings, $cart->order_source),
            );

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
}
