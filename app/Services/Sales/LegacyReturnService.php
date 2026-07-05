<?php

namespace App\Services\Sales;

use App\Models\CustomerReturn;
use App\Models\KraResponse;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Erp\CapabilityGate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegacyReturnService
{
    public function __construct(
        protected CustomerReturnService $customerReturnService,
        protected CreditNoteService $creditNoteService,
        protected LegacyOrderService $legacyOrders,
        protected CustomerReturnNumberAllocator $returnNumbers,
    ) {}

    public function baseQuery(User $user): Builder
    {
        $query = CustomerReturn::query()
            ->with(['lines.product.unit', 'sale', 'customer', 'returnedByUser', 'approvedByUser', 'creditNote'])
            ->where('organization_id', $user->organization_id)
            ->where('return_kind', 'legacy');

        app(UserAccessService::class)->scopeBranchIfLimited($query, $user);

        return $query;
    }

    public function paginate(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $this->baseQuery($user);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['sale_id'])) {
            $query->where('sale_id', (int) $filters['sale_id']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('return_date', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('return_date', '<=', $filters['to_date']);
        }

        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('return_no', 'like', "%{$q}%")
                    ->orWhereHas('sale', fn ($s) => $s->where('order_num', 'like', "%{$q}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$q}%"));
            });
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 200);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function findForUser(User $user, int $returnId): CustomerReturn
    {
        return $this->baseQuery($user)->findOrFail($returnId);
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $user, array $data): CustomerReturn
    {
        $organization = Organization::findOrFail($user->organization_id);
        $gate = new CapabilityGate($organization);
        $finance = $gate->moduleSettings('finance') ?? [];

        $this->assertKraDeviceEnabled($finance);

        $sale = $this->resolveLegacySale($user, (int) $data['sale_id']);
        $this->assertLegacyReturnAllowed($sale);
        $kraOriginalInvoice = $this->resolveKraOriginalInvoiceNumber(
            $sale,
            $data['kra_original_invoice_number'] ?? null,
        );

        return DB::transaction(function () use ($user, $data, $sale, $finance, $kraOriginalInvoice) {
            $lines = ! empty($data['full_return'])
                ? $this->fullReturnLines($sale)
                : $this->customerReturnService->normalizeLinesForSale(
                    $data['lines'] ?? [],
                    (int) $sale->id,
                    'legacy',
                );
            $total = round(array_sum(array_column($lines, 'amount')), 2);
            $sequence = $this->returnNumbers->nextForOrganization((int) $user->organization_id);

            $return = CustomerReturn::create([
                'return_no' => $this->formatLegacyReturnNo($sequence),
                'return_seq' => $sequence,
                'organization_id' => $user->organization_id,
                'branch_id' => (int) ($data['branch_id'] ?? $sale->branch_id ?? $user->branch_id),
                'sale_id' => $sale->id,
                'customer_num' => $data['customer_num'] ?? $sale->customer_num,
                'return_date' => $data['return_date'] ?? now()->toDateString(),
                'refund_method' => $data['refund_method'] ?? 'CASH',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'return_kind' => 'legacy',
                'kra_original_invoice_number' => $kraOriginalInvoice,
                'total_amount' => $total,
                'stock_location' => $data['stock_location'] ?? 'shop',
                'returned_by' => $user->id,
            ]);

            $this->customerReturnService->syncLinesPublic($return, $lines);
            $this->persistKraInvoiceOnSale($sale, $kraOriginalInvoice);

            $autoApprove = array_key_exists('auto_approve', $data)
                ? (bool) $data['auto_approve']
                : true;

            if ($autoApprove) {
                return $this->approve($return->fresh(['lines']), $user, $finance);
            }

            return $return->fresh(['lines', 'sale', 'customer', 'returnedByUser']);
        });
    }

    public function approve(CustomerReturn $return, User $user, ?array $finance = null): CustomerReturn
    {
        if ($return->return_kind !== 'legacy') {
            throw ValidationException::withMessages([
                'return_kind' => 'Only legacy returns can be approved through this endpoint.',
            ]);
        }

        if ($return->status === 'approved') {
            return $return->load(['lines', 'sale', 'customer', 'creditNote']);
        }

        if ($return->status === 'rejected') {
            throw ValidationException::withMessages([
                'status' => 'Rejected legacy returns cannot be approved.',
            ]);
        }

        $organization = Organization::findOrFail($user->organization_id);
        $gate = new CapabilityGate($organization);
        $finance ??= $gate->moduleSettings('finance') ?? [];
        $this->assertKraDeviceEnabled($finance);

        return DB::transaction(function () use ($return, $user, $finance) {
            $return->load(['lines', 'sale.items']);

            if ($return->sale_id) {
                $this->customerReturnService->validateLinesAgainstSalePublic(
                    (int) $return->sale_id,
                    $return->lines->map(fn ($line) => [
                        'sale_item_id' => $line->sale_item_id,
                        'product_code' => $line->product_code,
                        'quantity_sold' => $line->quantity_sold,
                        'return_qty' => $line->return_qty,
                    ])->all(),
                    $return->id,
                    'legacy',
                );
            }

            if ($return->sale_id) {
                $this->customerReturnService->applyReturnToSalePublic($return->fresh(['lines']));
            }

            $return->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'reject_reason' => null,
            ]);

            $return = $return->fresh(['lines', 'sale', 'customer']);
            $this->creditNoteService->createForReturn($return, $user, $finance);

            return $return->fresh(['lines', 'sale', 'customer', 'returnedByUser', 'approvedByUser', 'creditNote']);
        });
    }

    public function linesFromSale(User $user, int $saleId): array
    {
        $sale = $this->resolveLegacySale($user, $saleId);
        $this->assertLegacyReturnAllowed($sale);

        return $this->customerReturnService->linesFromSale($sale, 'legacy');
    }

    public function kraInvoiceHintForSale(Sale $sale): array
    {
        $originalKra = KraResponse::query()
            ->where('sale_id', $sale->id)
            ->where('status', 'success')
            ->orderByDesc('id')
            ->first();

        $stored = trim((string) (($sale->fulfillment_meta ?? [])['legacy_kra_invoice_number'] ?? ''));

        return [
            'requires_manual_invoice_number' => ! $originalKra && $stored === '',
            'known_invoice_number' => $stored !== ''
                ? $stored
                : ($originalKra ? $this->creditNoteService->relevantInvoiceFromKraResponse($originalKra) : null),
            'has_centrix_kra_response' => (bool) $originalKra,
        ];
    }

    protected function resolveLegacySale(User $user, int $saleId): Sale
    {
        $sale = Sale::query()
            ->where('organization_id', $user->organization_id)
            ->where('fulfillment_meta->legacy_import', true)
            ->find($saleId);

        if (! $sale) {
            throw ValidationException::withMessages([
                'sale_id' => 'Sale is not a materialized legacy order.',
            ]);
        }

        app(UserAccessService::class)->assertBranchAccess($user, (int) $sale->branch_id);

        return $sale;
    }

    protected function assertLegacyReturnAllowed(Sale $sale): void
    {
        $summary = $this->legacyOrders->legacyReturnSummaryForSale($sale);
        $returnNo = $summary['legacy_return_no'] ?? null;

        if (($summary['return_count_all'] ?? 0) > 0) {
            $message = $summary['fully_returned'] ?? false
                ? 'A legacy return has already been completed for this order.'
                : 'A legacy return is already in progress for this order.';

            if (is_string($returnNo) && $returnNo !== '') {
                $message .= " See {$returnNo} in Legacy returns.";
            }

            throw ValidationException::withMessages([
                'sale_id' => $message,
            ]);
        }
    }

    protected function resolveKraOriginalInvoiceNumber(Sale $sale, ?string $provided): string
    {
        $hint = $this->kraInvoiceHintForSale($sale);
        if (! $hint['requires_manual_invoice_number']) {
            return trim((string) ($hint['known_invoice_number'] ?? ''));
        }

        $provided = trim((string) $provided);
        if ($provided === '') {
            throw ValidationException::withMessages([
                'kra_original_invoice_number' => 'Original KRA CU invoice number is required for this legacy order.',
            ]);
        }

        return $provided;
    }

    protected function persistKraInvoiceOnSale(Sale $sale, string $invoiceNumber): void
    {
        if ($invoiceNumber === '') {
            return;
        }

        $meta = $sale->fulfillment_meta ?? [];
        if (! empty($meta['legacy_kra_invoice_number'])) {
            return;
        }

        $meta['legacy_kra_invoice_number'] = $invoiceNumber;
        $sale->update(['fulfillment_meta' => $meta]);
    }

    protected function assertKraDeviceEnabled(array $finance): void
    {
        if (empty($finance['enable_kra_device'])) {
            throw ValidationException::withMessages([
                'kra' => 'KRA device must be enabled before creating legacy returns.',
            ]);
        }
    }

    public function nextLegacyReturnNo(int $organizationId): string
    {
        return $this->formatLegacyReturnNo(
            $this->returnNumbers->nextForOrganization($organizationId),
        );
    }

    protected function formatLegacyReturnNo(int $sequence): string
    {
        return 'LRET-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /** @return list<array<string, mixed>> */
    protected function fullReturnLines(Sale $sale): array
    {
        $lines = $this->customerReturnService->linesFromSale($sale, 'legacy');

        return $this->customerReturnService->normalizeLinesForSale(
            array_values(array_filter(
                $lines,
                fn (array $line) => (float) ($line['return_qty'] ?? 0) > 0,
            )),
            (int) $sale->id,
            'legacy',
        );
    }
}
