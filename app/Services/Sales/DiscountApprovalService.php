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

    public function checkoutRequiresPendingApproval(TemporaryCart $cart, User $user, CapabilityGate $gate): bool
    {
        if (! $this->discountApprovalEnabled($gate->moduleSettings('sales'))) {
            return false;
        }

        if ($this->canAutoApproveDiscount($user)) {
            return false;
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

        if ($scope === 'order' && empty($salesSettings['enable_order_discount'])) {
            if (! $this->discountApprovalEnabled($salesSettings)) {
                throw ValidationException::withMessages(['scope' => 'Order discount is not enabled.']);
            }
        }

        if ($scope === 'line' && empty($salesSettings['allow_edit_line_discount'])) {
            if (! $this->discountApprovalEnabled($salesSettings)) {
                throw ValidationException::withMessages(['scope' => 'Manual line discounts are not enabled.']);
            }
        }

        $baseAmount = $this->resolveDiscountBase($cart, $scope, (string) ($data['line_ref'] ?? ''), $user, $gate);
        $percent = $this->discountPercent($discountAmount, $baseAmount);

        $needsApproval = $this->discountApprovalEnabled($salesSettings)
            && $discountAmount > 0.01
            && ! $this->canAutoApproveDiscount($user);

        if ($needsApproval && strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when requesting manager approval for large discounts.',
            ]);
        }

        if (! $needsApproval) {
            return [
                'applied' => true,
                'cart' => $this->applyDiscount($user, $cart, $gate, $scope, $discountAmount, (string) ($data['line_ref'] ?? '')),
            ];
        }

        $this->cancelPendingCartRequests($cart, $user);

        $cart = $this->applyDiscount($user, $cart, $gate, $scope, $discountAmount, (string) ($data['line_ref'] ?? ''));

        $requesterName = $user->full_name ?: $user->username;
        $percentLabel = number_format($percent, 1).'%';
        $title = $scope === 'order'
            ? 'Order discount approval required'
            : 'Line discount approval required';

        $actionRequest = app(ActionRequestService::class)->requestApproval($user, [
            'type' => 'discount',
            'module' => 'sales',
            'reference_type' => 'temporary_cart',
            'reference_id' => (int) $cart->id,
            'approver_permission' => 'sales.orders.approve',
            'title' => $title,
            'message' => "{$requesterName} requested a {$percentLabel} discount on cart #{$cart->id}.",
            'reason' => $reason,
            'severity' => 'warning',
            'action_url' => $this->discountActionUrl($cart),
            'payload' => [
                'scope' => $scope,
                'line_ref' => $data['line_ref'] ?? null,
                'discount_amount' => $discountAmount,
                'discount_percent' => round($percent, 2),
                'action_url' => $this->discountActionUrl($cart),
                'cart_id' => (int) $cart->id,
                'channel' => (string) ($cart->channel ?? 'pos'),
                'order_source' => (string) ($cart->order_source ?? ''),
            ],
        ]);

        return [
            'applied' => false,
            'action_request' => $actionRequest,
            'cart' => $cart->fresh('lines'),
        ];
    }

    public function attachCheckoutToSale(Sale $sale, TemporaryCart $cart, User $user): void
    {
        $pending = $this->pendingRequestForCart($cart, $user);
        if ($pending === null) {
            return;
        }

        $payload = $pending->payload ?? [];
        $payload['sale_id'] = (int) $sale->id;
        $payload['order_num'] = (int) $sale->order_num;
        $payload['action_url'] = $this->saleActionUrl($sale);

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

    protected function cancelPendingCartRequests(TemporaryCart $cart, User $user): void
    {
        ActionRequest::query()
            ->where('organization_id', $user->organization_id)
            ->where('type', 'discount')
            ->where('reference_type', 'temporary_cart')
            ->where('reference_id', $cart->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
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
