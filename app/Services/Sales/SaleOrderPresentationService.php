<?php

namespace App\Services\Sales;

use App\Models\ActionRequest;
use App\Models\Sale;
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
            return $this->applyPresentationAttributes($sale, $user, $pendingRequests->get($sale->id));
        });
    }

    public function enrichSale(Sale $sale, User $user, CapabilityGate $gate): Sale
    {
        $pending = $this->pendingDiscountRequestsForSales(collect([$sale]), $user)->get($sale->id);

        return $this->applyPresentationAttributes($sale, $user, $pending);
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
            'follow_up_message' => 'Your discount was not approved. Please contact the office for further follow-up.',
            'highlight' => 'discount_rejected',
        ];
    }

    /** @return array<string, mixed>|null */
    public function presentActionRequest(?ActionRequest $request, User $viewer): ?array
    {
        if ($request === null) {
            return null;
        }

        return [
            'id' => (int) $request->id,
            'type' => $request->type,
            'status' => $request->status,
            'reason' => $request->reason,
            'payload' => $request->payload,
            'can_approve' => $this->actionRequests->canApprove($viewer, $request),
        ];
    }

    protected function applyPresentationAttributes(Sale $sale, User $user, ?ActionRequest $pendingRequest): Sale
    {
        $sale->setAttribute('total_discount', $this->totalDiscount($sale));
        $sale->setAttribute('action_request', $this->presentActionRequest($pendingRequest, $user));
        $sale->setAttribute('discount_approval_reason', $this->discountApprovalReason($sale, $pendingRequest));
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
