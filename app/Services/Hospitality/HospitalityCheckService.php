<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\HospitalityCheckLine;
use App\Models\HospitalityCheckPayment;
use App\Models\HospitalityFloorTable;
use App\Models\HospitalityOutlet;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HospitalityCheckService
{
    /** Draft + collectible statuses that can still receive payments / edits. */
    public const EDITABLE_STATUSES = ['open', 'unpaid', 'partially_paid'];

    /** Legacy aliases still accepted when reading older rows before migrate. */
    public const COLLECTIBLE_STATUSES = ['unpaid', 'partially_paid', 'held'];

    public const PAID_STATUSES = ['paid', 'settled'];

    public function ensureDefaultOutlet(Organization $org, ?int $branchId = null): HospitalityOutlet
    {
        $outlet = HospitalityOutlet::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($outlet) {
            return $outlet;
        }

        return HospitalityOutlet::create([
            'organization_id' => $org->id,
            'branch_id' => $branchId,
            'code' => 'MAIN',
            'name' => 'Main outlet',
            'outlet_type' => 'bar',
            'is_active' => true,
        ]);
    }

    public function openCheck(
        Organization $org,
        User $user,
        ?int $branchId = null,
        ?int $outletId = null,
        ?int $floorTableId = null,
    ): HospitalityCheck {
        $outlet = $outletId
            ? HospitalityOutlet::query()
                ->where('organization_id', $org->id)
                ->where('id', $outletId)
                ->where('is_active', true)
                ->firstOrFail()
            : $this->ensureDefaultOutlet($org, $branchId);

        $tableId = null;
        $serviceMode = 'counter';
        if ($floorTableId) {
            $table = $this->resolveFloorTable($org, (int) $outlet->id, $floorTableId);
            $tableId = $table->id;
            $serviceMode = 'table';
        }

        return HospitalityCheck::create([
            'organization_id' => $org->id,
            'branch_id' => $branchId ?? $outlet->branch_id,
            'outlet_id' => $outlet->id,
            'floor_table_id' => $tableId,
            'check_number' => $this->nextCheckNumber((int) $org->id),
            'status' => 'open',
            'service_mode' => $serviceMode,
            'opened_by' => $user->id,
            'subtotal' => 0,
            'vat_total' => 0,
            'service_charge' => 0,
            'total' => 0,
            'amount_paid' => 0,
            'opened_at' => now(),
        ]);
    }

    public function findOwnedCheck(int $checkId, int $organizationId): HospitalityCheck
    {
        return HospitalityCheck::query()
            ->with([
                'lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'floorTable:id,code,label,outlet_id',
            ])
            ->where('organization_id', $organizationId)
            ->where('id', $checkId)
            ->firstOrFail();
    }

    public function assignFloorTable(HospitalityCheck $check, Organization $org, ?int $floorTableId): HospitalityCheck
    {
        $this->assertEditable($check);
        if (! $floorTableId) {
            $check->update([
                'floor_table_id' => null,
                'service_mode' => 'counter',
            ]);

            return $this->presentable($check->fresh());
        }

        $table = $this->resolveFloorTable($org, (int) $check->outlet_id, $floorTableId);
        $check->update([
            'floor_table_id' => $table->id,
            'service_mode' => 'table',
        ]);

        return $this->presentable($check->fresh());
    }

    public function addProductLine(HospitalityCheck $check, string $productCode, float $qty = 1): HospitalityCheck
    {
        $this->assertEditable($check);

        $product = Product::query()
            ->where('organization_id', $check->organization_id)
            ->where('product_code', $productCode)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $qty = max(0.0001, $qty);
        $unitPrice = round((float) $product->unit_price, 2);
        $vatPct = 0.0;
        if ($product->vat_id) {
            $vatPct = (float) (Vat::query()->where('id', $product->vat_id)->value('vat_percentage') ?? 0);
        }

        $existing = HospitalityCheckLine::query()
            ->where('check_id', $check->id)
            ->where('product_code', $product->product_code)
            ->orderBy('id')
            ->first();

        if ($existing) {
            $newQty = (float) $existing->qty + $qty;
            $lineTotal = round($unitPrice * $newQty, 2);
            $vatAmount = round($lineTotal * $vatPct / (100 + $vatPct), 2);
            $existing->update([
                'qty' => $newQty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'vat_amount' => $vatAmount,
                'vat_id' => $product->vat_id,
                'description' => (string) $product->product_name,
            ]);
        } else {
            $sort = (int) HospitalityCheckLine::query()->where('check_id', $check->id)->max('sort_order') + 1;
            $lineTotal = round($unitPrice * $qty, 2);
            $vatAmount = round($lineTotal * $vatPct / (100 + $vatPct), 2);
            HospitalityCheckLine::create([
                'organization_id' => $check->organization_id,
                'check_id' => $check->id,
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'description' => (string) $product->product_name,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'vat_amount' => $vatAmount,
                'vat_id' => $product->vat_id,
                'sort_order' => $sort,
            ]);
        }

        return $this->recalculate($check->fresh());
    }

    public function updateLineQty(HospitalityCheck $check, int $lineId, float $qty): HospitalityCheck
    {
        $this->assertEditable($check);
        $line = HospitalityCheckLine::query()
            ->where('check_id', $check->id)
            ->where('id', $lineId)
            ->firstOrFail();

        if ($qty <= 0) {
            $line->delete();

            return $this->recalculate($check->fresh());
        }

        $vatPct = 0.0;
        if ($line->vat_id) {
            $vatPct = (float) (Vat::query()->where('id', $line->vat_id)->value('vat_percentage') ?? 0);
        }
        $lineTotal = round((float) $line->unit_price * $qty, 2);
        $line->update([
            'qty' => $qty,
            'line_total' => $lineTotal,
            'vat_amount' => round($lineTotal * $vatPct / (100 + $vatPct), 2),
        ]);

        return $this->recalculate($check->fresh());
    }

    public function removeLine(HospitalityCheck $check, int $lineId): HospitalityCheck
    {
        $this->assertEditable($check);
        HospitalityCheckLine::query()
            ->where('check_id', $check->id)
            ->where('id', $lineId)
            ->delete();

        return $this->recalculate($check->fresh());
    }

    public function clearLines(HospitalityCheck $check): HospitalityCheck
    {
        $this->assertEditable($check);
        if (round((float) $check->amount_paid, 2) > 0) {
            throw ValidationException::withMessages([
                'check' => ['Cannot clear lines after a partial payment has been recorded.'],
            ]);
        }
        HospitalityCheckLine::query()->where('check_id', $check->id)->delete();

        return $this->recalculate($check->fresh());
    }

    /** Park / hold an open check as unpaid (same payment queue as Save order). */
    public function hold(HospitalityCheck $check, Organization $org): HospitalityCheck
    {
        return $this->saveWithoutPayment($check, $org);
    }

    public function resume(HospitalityCheck $check): HospitalityCheck
    {
        if (! in_array($check->status, self::COLLECTIBLE_STATUSES, true) && $check->status !== 'open') {
            throw ValidationException::withMessages(['check' => ['Only unpaid or open checks can be resumed.']]);
        }
        if (in_array($check->status, self::PAID_STATUSES, true) || $check->status === 'void') {
            throw ValidationException::withMessages(['check' => ['This check cannot be resumed.']]);
        }
        // Keep unpaid / partially_paid for collect-later; reopen only when still zero paid draft-held.
        if (round((float) $check->amount_paid, 2) <= 0 && in_array($check->status, ['unpaid', 'held'], true)) {
            $check->update(['status' => 'open']);
        }

        return $this->presentable($check->fresh());
    }

    public function settleCash(HospitalityCheck $check, User $user, Organization $org, ?float $amount = null): HospitalityCheck
    {
        $check = $this->recalculate($check->fresh());
        $balance = $this->balanceDue($check);
        $pay = $amount === null ? $balance : round($amount, 2);

        return $this->settleWithPayments($check, $user, $org, [
            [
                'method_code' => 'CASH',
                'amount' => $pay,
                'reference' => $pay > $balance ? 'cash_tendered:'.$pay : null,
            ],
        ], $pay);
    }

    /**
     * @param  list<array{method_code: string, amount: float|int|string, reference?: ?string}>  $payments
     */
    public function settleWithPayments(
        HospitalityCheck $check,
        User $user,
        Organization $org,
        array $payments,
        ?float $tenderedTotal = null,
        ?int $folioId = null,
    ): HospitalityCheck {
        $this->assertCollectable($check);
        $this->assertTableSelectedIfRequired($check, $org);
        $check = $this->recalculate($check->fresh());
        if ($check->lines()->count() < 1) {
            throw ValidationException::withMessages(['check' => ['Cannot collect payment on an empty check.']]);
        }

        $workflow = HospitalityPaymentWorkflow::forOrganization($org);
        if (! $workflow['paid'] && ! $workflow['partially_paid']) {
            throw ValidationException::withMessages([
                'workflow' => ['No payment statuses are enabled for this organization.'],
            ]);
        }

        $due = round((float) $check->total, 2);
        $alreadyPaid = round((float) $check->amount_paid, 2);
        $balance = round(max(0, $due - $alreadyPaid), 2);

        $normalized = [];
        $incoming = 0.0;
        $hasRoomCharge = false;
        foreach ($payments as $row) {
            $code = strtoupper(trim((string) ($row['method_code'] ?? '')));
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($code === '' || $amount <= 0) {
                continue;
            }
            if (! in_array($code, ['CASH', 'MPESA', 'EQUITY', 'KCB', 'OTHER', 'CARD', 'CHEQUE', 'BANK', 'ROOM'], true)) {
                throw ValidationException::withMessages(['payments' => ["Unsupported payment method: {$code}"]]);
            }
            if ($code === 'ROOM') {
                $hasRoomCharge = true;
            }
            $normalized[] = [
                'method_code' => $code,
                'amount' => $amount,
                'reference' => isset($row['reference']) ? (string) $row['reference'] : null,
            ];
            $incoming += $amount;
        }

        $targetFolioId = $folioId ?? ($check->folio_id ? (int) $check->folio_id : null);
        if ($hasRoomCharge) {
            if (! HospitalityServices::enabled($org, 'room_charge')) {
                throw ValidationException::withMessages([
                    'payments' => ['Room charge is not enabled for this organization.'],
                ]);
            }
            if (! $targetFolioId) {
                throw ValidationException::withMessages([
                    'folio_id' => ['Select an open guest folio for room charge.'],
                ]);
            }
        }
        $incoming = round($incoming, 2);
        if ($incoming <= 0) {
            throw ValidationException::withMessages(['payments' => ['Enter at least one payment amount.']]);
        }

        $tendered = $tenderedTotal === null ? $incoming : round($tenderedTotal, 2);
        $appliedTowardBalance = min($incoming, $balance);
        $newPaid = round($alreadyPaid + $appliedTowardBalance, 2);
        $isFull = $newPaid + 0.001 >= $due;
        $isPartial = ! $isFull && $newPaid > 0.001;

        if ($isPartial && ! $workflow['partially_paid']) {
            throw ValidationException::withMessages([
                'payments' => ['Partial payments are not enabled. Collect the full balance.'],
            ]);
        }
        if ($isFull && ! $workflow['paid']) {
            throw ValidationException::withMessages([
                'payments' => ['Paid status is not enabled for this organization.'],
            ]);
        }
        if (! $isFull && ! $isPartial) {
            throw ValidationException::withMessages(['payments' => ['Payment total must be greater than zero.']]);
        }

        $appliedTowardBalance = min($incoming, $balance);

        return DB::transaction(function () use (
            $check,
            $user,
            $org,
            $normalized,
            $tendered,
            $balance,
            $newPaid,
            $isFull,
            $appliedTowardBalance,
            $hasRoomCharge,
            $targetFolioId,
        ) {
            $remainingToRecord = $appliedTowardBalance;
            $roomChargeAmount = 0.0;
            foreach ($normalized as $row) {
                if ($remainingToRecord <= 0.001) {
                    break;
                }
                $recordAmount = min($row['amount'], $remainingToRecord);
                $remainingToRecord = round($remainingToRecord - $recordAmount, 2);
                $reference = $row['reference'];
                if ($row['method_code'] === 'CASH' && $tendered > $balance + 0.001 && ! $reference) {
                    $reference = 'cash_tendered:'.$tendered;
                }
                if ($row['method_code'] === 'ROOM') {
                    $roomChargeAmount = round($roomChargeAmount + $recordAmount, 2);
                }
                HospitalityCheckPayment::create([
                    'organization_id' => $check->organization_id,
                    'check_id' => $check->id,
                    'method_code' => $row['method_code'],
                    'amount' => $recordAmount,
                    'reference' => $reference,
                    'received_by' => $user->id,
                ]);
            }

            if ($hasRoomCharge && $roomChargeAmount > 0 && $targetFolioId) {
                $folioService = app(HospitalityFolioService::class);
                $folio = $folioService->find($org, $targetFolioId);
                $folioService->addCharge(
                    $folio,
                    $user,
                    'fnb',
                    'F&B check '.$check->check_number,
                    $roomChargeAmount,
                    (float) $check->vat_total,
                    (int) $check->id,
                );
                $check->folio_id = $targetFolioId;
            }

            $status = $isFull ? 'paid' : 'partially_paid';
            $check->update([
                'status' => $status,
                'amount_paid' => $newPaid,
                'folio_id' => $check->folio_id,
                'closed_by' => $isFull ? $user->id : $check->closed_by,
                'closed_at' => $isFull ? now() : $check->closed_at,
            ]);

            $fresh = $check->fresh();
            if ($isFull) {
                app(HospitalityCheckStockService::class)->deductForSettledCheck($fresh, $user);
            }

            return $this->presentable($fresh->fresh());
        });
    }

    /** Save without collecting payment → unpaid (receipt for later collection). */
    public function saveWithoutPayment(HospitalityCheck $check, Organization $org): HospitalityCheck
    {
        $this->assertEditable($check);
        $this->assertTableSelectedIfRequired($check, $org);
        if ($check->lines()->count() < 1) {
            throw ValidationException::withMessages(['check' => ['Add at least one item before saving.']]);
        }
        if (! HospitalityPaymentWorkflow::enabled($org, 'unpaid')) {
            throw ValidationException::withMessages([
                'workflow' => ['Unpaid orders are not enabled. Use Collect payment instead.'],
            ]);
        }
        if (round((float) $check->amount_paid, 2) > 0) {
            throw ValidationException::withMessages([
                'check' => ['This check already has payments — collect the balance instead of saving unpaid.'],
            ]);
        }

        $check->update(['status' => 'unpaid']);

        return $this->presentable($check->fresh());
    }

    public function voidOpen(HospitalityCheck $check): HospitalityCheck
    {
        if (! in_array($check->status, ['open', 'unpaid', 'held'], true)) {
            throw ValidationException::withMessages([
                'check' => ['Only open or unpaid checks with no payments can be voided.'],
            ]);
        }
        if (round((float) $check->amount_paid, 2) > 0) {
            throw ValidationException::withMessages(['check' => ['Cannot void a check that has payments.']]);
        }
        $check->update(['status' => 'void', 'closed_at' => now()]);

        return $this->presentable($check->fresh());
    }

    /**
     * Unpaid + partially paid checks awaiting cashier collection.
     *
     * @return list<HospitalityCheck>
     */
    public function listCollectible(int $organizationId, ?int $outletId = null): array
    {
        $query = HospitalityCheck::query()
            ->with([
                'lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'floorTable:id,code,label',
            ])
            ->where('organization_id', $organizationId)
            ->whereIn('status', self::COLLECTIBLE_STATUSES)
            ->orderByDesc('updated_at')
            ->limit(80);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        return $query->get()->all();
    }

    /** @deprecated use listCollectible */
    public function listHeld(int $organizationId, ?int $outletId = null): array
    {
        return $this->listCollectible($organizationId, $outletId);
    }

    public function recalculate(HospitalityCheck $check): HospitalityCheck
    {
        $lines = HospitalityCheckLine::query()->where('check_id', $check->id)->get();
        $subtotal = round((float) $lines->sum('line_total'), 2);
        $vatTotal = round((float) $lines->sum('vat_amount'), 2);
        $check->update([
            'subtotal' => $subtotal,
            'vat_total' => $vatTotal,
            'total' => $subtotal,
        ]);

        return $this->presentable($check->fresh());
    }

    public function presentable(HospitalityCheck $check): HospitalityCheck
    {
        return $check->load([
            'lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'floorTable:id,code,label,outlet_id',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(HospitalityCheck $check): array
    {
        $check = $this->presentable($check);
        $total = (float) $check->total;
        $paid = (float) $check->amount_paid;
        $balance = round(max(0, $total - $paid), 2);

        return [
            'id' => $check->id,
            'check_number' => $check->check_number,
            'status' => $this->normalizeStatus((string) $check->status),
            'service_mode' => $check->service_mode,
            'outlet_id' => $check->outlet_id,
            'floor_table_id' => $check->floor_table_id,
            'floor_table' => $check->floorTable ? [
                'id' => $check->floorTable->id,
                'code' => $check->floorTable->code,
                'label' => $check->floorTable->label,
            ] : null,
            'subtotal' => (float) $check->subtotal,
            'vat_total' => (float) $check->vat_total,
            'service_charge' => (float) $check->service_charge,
            'total' => $total,
            'amount_paid' => $paid,
            'balance_due' => $balance,
            'opened_at' => optional($check->opened_at)?->toIso8601String(),
            'closed_at' => optional($check->closed_at)?->toIso8601String(),
            'lines' => $check->lines->map(fn (HospitalityCheckLine $line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_code' => $line->product_code,
                'description' => $line->description,
                'qty' => (float) $line->qty,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
                'vat_amount' => (float) $line->vat_amount,
                'sort_order' => (int) $line->sort_order,
            ])->values()->all(),
        ];
    }

    public function balanceDue(HospitalityCheck $check): float
    {
        return round(max(0, (float) $check->total - (float) $check->amount_paid), 2);
    }

    protected function normalizeStatus(string $status): string
    {
        return match ($status) {
            'held' => 'unpaid',
            'settled', 'posted_to_folio' => 'paid',
            default => $status,
        };
    }

    protected function assertEditable(HospitalityCheck $check): void
    {
        $status = $this->normalizeStatus((string) $check->status);
        if (! in_array($status, self::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages(['check' => ['This check can no longer be edited.']]);
        }
    }

    protected function assertCollectable(HospitalityCheck $check): void
    {
        $status = $this->normalizeStatus((string) $check->status);
        if (! in_array($status, ['open', 'unpaid', 'partially_paid'], true)) {
            throw ValidationException::withMessages(['check' => ['This check cannot accept payments.']]);
        }
    }

    protected function assertTableSelectedIfRequired(HospitalityCheck $check, Organization $org): void
    {
        if (! HospitalityServices::enabled($org, 'table_pos')) {
            return;
        }
        if (! $check->floor_table_id) {
            throw ValidationException::withMessages([
                'floor_table_id' => ['Select a table before saving or collecting payment.'],
            ]);
        }
    }

    protected function resolveFloorTable(Organization $org, int $outletId, int $floorTableId): HospitalityFloorTable
    {
        return HospitalityFloorTable::query()
            ->where('organization_id', $org->id)
            ->where('outlet_id', $outletId)
            ->where('id', $floorTableId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    protected function nextCheckNumber(int $organizationId): string
    {
        $prefix = 'H'.now()->format('ymd');
        $last = HospitalityCheck::query()
            ->where('organization_id', $organizationId)
            ->where('check_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('check_number');

        $seq = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
