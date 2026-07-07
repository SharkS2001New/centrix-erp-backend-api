<?php

namespace App\Services\Sales;

use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Services\Accounting\ReferenceJournalReversalService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Support\Facades\DB;

class OrderExpiryService
{
    public function __construct(
        protected SaleInventoryRestorer $inventoryRestorer,
        protected ReferenceJournalReversalService $journalReversals,
    ) {}

    public function expireStaleOrdersForOrganization(Organization $organization): int
    {
        $gate = app(CapabilityGate::class)->forOrganization($organization);
        $salesSettings = $gate->moduleSettings('sales');

        if (($salesSettings['order_expiry_enabled'] ?? true) === false) {
            return 0;
        }

        $days = max(1, min(90, (int) ($salesSettings['order_expiry_days'] ?? 5)));
        $cutoff = now()->subDays($days);
        $expirableStatuses = $this->expirableStatuses($gate);

        if ($expirableStatuses === []) {
            return 0;
        }

        $actor = $this->resolveActor($organization);
        if (! $actor) {
            return 0;
        }

        $count = 0;
        Sale::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', $expirableStatuses)
            ->whereNull('expired_at')
            ->whereNull('cancelled_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($sales) use ($gate, $actor, &$count) {
                foreach ($sales as $sale) {
                    if ($this->expireSale($sale, $actor, $gate)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function expireSale(Sale $sale, User $actor, ?CapabilityGate $gate = null): bool
    {
        if (in_array((string) $sale->status, ['cancelled', 'expired', 'completed', 'draft', 'held'], true)) {
            return false;
        }

        $gate ??= app(CapabilityGate::class)->forOrganization(
            Organization::findOrFail((int) $sale->organization_id),
        );

        if (! in_array((string) $sale->status, $this->expirableStatuses($gate), true)) {
            return false;
        }

        DB::transaction(function () use ($sale, $actor, $gate) {
            $this->inventoryRestorer->restore($sale, $actor);

            $sale->update([
                'status' => 'expired',
                'expired_at' => now(),
                'expired_by' => $actor->id,
                'stock_balanced' => 0,
            ]);

            $this->journalReversals->reverseIfEnabled(
                'sale',
                (int) $sale->id,
                $actor,
                $gate,
            );

            app(ActionRequestService::class)->cancelAllPendingForSale(
                $sale->fresh(),
                $actor,
                'Order expired.',
            );
        });

        return true;
    }

    /** @return list<string> */
    public function expirableStatuses(CapabilityGate $gate): array
    {
        $salesSettings = $gate->moduleSettings('sales');
        $configured = $salesSettings['order_expiry_statuses'] ?? null;

        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_map('strval', $configured)));
        }

        $workflow = OrderWorkflowService::forGate($gate)->config();
        $beforeStatus = (string) ($salesSettings['order_expiry_before_status']
            ?? $gate->distributionSettings()['assign_on_status']
            ?? 'processed');

        $statuses = [];
        foreach ($workflow['steps'] ?? [] as $step) {
            if (($step['enabled'] ?? true) === false) {
                continue;
            }
            $status = (string) ($step['status'] ?? '');
            if ($status === '' || $status === $beforeStatus) {
                break;
            }
            $statuses[] = $status;
        }

        if ($statuses === []) {
            return ['booked', 'pending', 'unpaid'];
        }

        return $statuses;
    }

    protected function resolveActor(Organization $organization): ?User
    {
        return User::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('is_admin', true)->orWhere('is_super_admin', true);
            })
            ->orderByDesc('is_admin')
            ->first();
    }
}
