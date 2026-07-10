<?php

namespace App\Services\Sales;

use App\Models\ActionRequest;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Support\Collection;

class SaleOrderPresentationService
{
    public function __construct(
        protected UserPermissionService $permissions,
        protected ActionRequestService $actionRequests,
    ) {}

    /** @param  Collection<int, Sale>  $sales */
    public function enrichCollection(Collection $sales, User $user, CapabilityGate $gate): Collection
    {
        if ($sales->isEmpty()) {
            return $sales;
        }

        $pendingRequests = $this->pendingDiscountRequestsForSales($sales, $user);

        return $sales->map(function (Sale $sale) use ($user, $pendingRequests) {
            return $this->enrichSaleItems($this->applyPresentationAttributes($sale, $user, $pendingRequests->get($sale->id)));
        });
    }

    public function enrichSale(Sale $sale, User $user, CapabilityGate $gate): Sale
    {
        $pending = $this->pendingDiscountRequestsForSales(collect([$sale]), $user)->get($sale->id);

        return $this->enrichSaleItems($this->applyPresentationAttributes($sale, $user, $pending));
    }

    public function enrichSaleItems(Sale $sale): Sale
    {
        if (! $sale->relationLoaded('items') || $sale->items->isEmpty()) {
            return $sale;
        }

        $display = app(SaleLineQuantityDisplayService::class);
        $sale->setRelation(
            'items',
            $sale->items->map(fn (SaleItem $item) => $this->presentSaleItem($item, $display)),
        );

        return $sale;
    }

    protected function presentSaleItem(SaleItem $item, SaleLineQuantityDisplayService $display): SaleItem
    {
        $product = $item->product ?? new Product(['product_code' => $item->product_code]);
        $isRetail = (bool) $item->on_wholesale_retail;
        $baseQty = (float) $item->quantity;
        $amount = (float) $item->amount;
        $discount = (float) ($item->discount_given ?? 0);

        $item->setAttribute(
            'display_unit_price',
            $display->displayUnitPrice($baseQty, $amount, $product, $isRetail, $discount),
        );
        $item->setAttribute(
            'display_discount_per_unit',
            $display->displayDiscountPerUnit($baseQty, $discount, $product, $isRetail),
        );
        $item->setAttribute(
            'display_amount',
            $display->displayLineAmount($baseQty, $amount, $product, $isRetail, $discount),
        );

        return $item;
    }

    public function totalDiscount(Sale $sale): float
    {
        $lineDiscount = 0.0;
        if ($sale->relationLoaded('items')) {
            $lineDiscount = (float) $sale->items->sum(fn ($item) => (float) ($item->discount_given ?? 0));
        }

        return round($lineDiscount + (float) ($sale->order_discount ?? 0), 2);
    }

    /** @return array<string, mixed>|null */
    public function discountRejectionPresentation(Sale $sale): ?array
    {
        if ((string) $sale->status !== 'editable') {
            return null;
        }

        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $approval = is_array($meta['discount_approval'] ?? null) ? $meta['discount_approval'] : [];
        if (empty($approval['rejected_at'])) {
            return null;
        }

        return [
            'rejected' => true,
            'rejected_at' => $approval['rejected_at'] ?? null,
            'rejection_reason' => $approval['rejection_reason'] ?? null,
            'rejection_guidance_type' => $approval['rejection_guidance_type'] ?? 'remove_discount',
            'advised_discount_amount' => isset($approval['advised_discount_amount'])
                ? round((float) $approval['advised_discount_amount'], 2)
                : null,
            'advised_discount_lines' => is_array($approval['advised_discount_lines'] ?? null)
                ? $approval['advised_discount_lines']
                : [],
            'guidance_message' => $this->discountRejectionGuidanceMessage($approval),
            'follow_up_message' => 'Your discount was not approved. Please contact the office for further follow-up.',
            'highlight' => 'discount_rejected',
        ];
    }

    /** @param  array<string, mixed>  $approval */
    public function discountRejectionGuidanceMessage(array $approval): string
    {
        $guidance = (string) ($approval['rejection_guidance_type'] ?? 'remove_discount');
        if ($guidance === 'advised_amount') {
            $lines = is_array($approval['advised_discount_lines'] ?? null) ? $approval['advised_discount_lines'] : [];
            if ($lines !== []) {
                $parts = [];
                foreach ($lines as $line) {
                    $name = trim((string) ($line['product_name'] ?? $line['product_code'] ?? 'Item'));
                    $amount = round((float) ($line['advised_discount'] ?? 0), 2);
                    $parts[] = $name.': KES '.number_format($amount, 2);
                }

                return 'Advised discounts — '.implode('; ', $parts);
            }

            $amount = round((float) ($approval['advised_discount_amount'] ?? 0), 2);

            return 'Advised discount: KES '.number_format($amount, 2);
        }

        return 'Remove all discounts from this order';
    }

    /** @return array<string, mixed>|null */
    public function presentActionRequest(?ActionRequest $request, User $viewer): ?array
    {
        if ($request === null) {
            return null;
        }

        return app(ActionRequestService::class)->presentForViewer($request, $viewer);
    }

    protected function applyPresentationAttributes(Sale $sale, User $user, ?ActionRequest $pendingRequest): Sale
    {
        $sale->setAttribute('total_discount', $this->totalDiscount($sale));
        $sale->setAttribute('action_request', $this->presentActionRequest($pendingRequest, $user));
        $sale->setAttribute('discount_approval_reason', $this->discountApprovalReason($sale, $pendingRequest));
        $sale->setAttribute('advised_discount_applied', $this->advisedDiscountApplied($sale, $pendingRequest));
        $rejection = $this->discountRejectionPresentation($sale);
        $sale->setAttribute('discount_rejection', $rejection);
        $sale->setAttribute('discount_rejected', $rejection !== null);

        return $sale;
    }

    protected function discountApprovalReason(Sale $sale, ?ActionRequest $pendingRequest): ?string
    {
        $fromRequest = trim((string) ($pendingRequest?->reason ?? ''));
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $stored = trim((string) (($meta['discount_approval']['reason'] ?? '') ?: ''));

        return $stored !== '' ? $stored : null;
    }

    protected function advisedDiscountApplied(Sale $sale, ?ActionRequest $pendingRequest): bool
    {
        if (! empty($pendingRequest?->payload['advised_discount_applied'])) {
            return true;
        }

        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $approval = is_array($meta['discount_approval'] ?? null) ? $meta['discount_approval'] : [];

        return ! empty($approval['advised_discount_applied']);
    }

    /** @param  Collection<int, Sale>  $sales
     * @return Collection<int, ActionRequest>
     */
    protected function pendingDiscountRequestsForSales(Collection $sales, User $user): Collection
    {
        $saleIds = $sales->pluck('id')->map(fn ($id) => (int) $id)->filter()->values()->all();
        if ($saleIds === []) {
            return collect();
        }

        return ActionRequest::query()
            ->where('organization_id', $user->organization_id)
            ->where('type', 'discount')
            ->where('reference_type', 'sale')
            ->whereIn('reference_id', $saleIds)
            ->where('status', 'pending')
            ->get()
            ->keyBy('reference_id');
    }
}
