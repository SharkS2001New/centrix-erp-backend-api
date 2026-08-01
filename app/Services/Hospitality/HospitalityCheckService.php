<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\HospitalityCheckLine;
use App\Models\HospitalityCheckPayment;
use App\Models\HospitalityOutlet;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HospitalityCheckService
{
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

    public function openCheck(Organization $org, User $user, ?int $branchId = null, ?int $outletId = null): HospitalityCheck
    {
        $outlet = $outletId
            ? HospitalityOutlet::query()
                ->where('organization_id', $org->id)
                ->where('id', $outletId)
                ->where('is_active', true)
                ->firstOrFail()
            : $this->ensureDefaultOutlet($org, $branchId);

        return HospitalityCheck::create([
            'organization_id' => $org->id,
            'branch_id' => $branchId ?? $outlet->branch_id,
            'outlet_id' => $outlet->id,
            'check_number' => $this->nextCheckNumber((int) $org->id),
            'status' => 'open',
            'service_mode' => 'counter',
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
            ->with(['lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->where('organization_id', $organizationId)
            ->where('id', $checkId)
            ->firstOrFail();
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
        HospitalityCheckLine::query()->where('check_id', $check->id)->delete();

        return $this->recalculate($check->fresh());
    }

    public function hold(HospitalityCheck $check): HospitalityCheck
    {
        $this->assertEditable($check);
        if ($check->lines()->count() < 1) {
            throw ValidationException::withMessages(['check' => ['Add at least one item before holding.']]);
        }
        $check->update(['status' => 'held']);

        return $this->presentable($check->fresh());
    }

    public function resume(HospitalityCheck $check): HospitalityCheck
    {
        if (! in_array($check->status, ['held', 'open'], true)) {
            throw ValidationException::withMessages(['check' => ['Only held or open checks can be resumed.']]);
        }
        $check->update(['status' => 'open']);

        return $this->presentable($check->fresh());
    }

    public function settleCash(HospitalityCheck $check, User $user, ?float $amount = null): HospitalityCheck
    {
        $this->assertEditable($check);
        $check = $this->recalculate($check->fresh());
        if ($check->lines()->count() < 1) {
            throw ValidationException::withMessages(['check' => ['Cannot settle an empty check.']]);
        }

        $due = round((float) $check->total, 2);
        $pay = $amount === null ? $due : round($amount, 2);
        if ($pay + 0.001 < $due) {
            throw ValidationException::withMessages(['amount' => ['Amount is less than the check total.']]);
        }

        return DB::transaction(function () use ($check, $user, $due, $pay) {
            HospitalityCheckPayment::create([
                'organization_id' => $check->organization_id,
                'check_id' => $check->id,
                'method_code' => 'CASH',
                'amount' => $due,
                'reference' => $pay > $due ? 'cash_tendered:'.$pay : null,
                'received_by' => $user->id,
            ]);

            $check->update([
                'status' => 'settled',
                'amount_paid' => $due,
                'closed_by' => $user->id,
                'closed_at' => now(),
            ]);

            return $this->presentable($check->fresh());
        });
    }

    public function voidOpen(HospitalityCheck $check): HospitalityCheck
    {
        if (! in_array($check->status, ['open', 'held'], true)) {
            throw ValidationException::withMessages(['check' => ['Only open or held checks can be voided.']]);
        }
        $check->update(['status' => 'void', 'closed_at' => now()]);

        return $this->presentable($check->fresh());
    }

    /**
     * @return list<HospitalityCheck>
     */
    public function listHeld(int $organizationId, ?int $outletId = null): array
    {
        $query = HospitalityCheck::query()
            ->with(['lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->where('organization_id', $organizationId)
            ->where('status', 'held')
            ->orderByDesc('updated_at')
            ->limit(50);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        return $query->get()->all();
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
        return $check->load(['lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(HospitalityCheck $check): array
    {
        $check = $this->presentable($check);

        return [
            'id' => $check->id,
            'check_number' => $check->check_number,
            'status' => $check->status,
            'service_mode' => $check->service_mode,
            'outlet_id' => $check->outlet_id,
            'subtotal' => (float) $check->subtotal,
            'vat_total' => (float) $check->vat_total,
            'service_charge' => (float) $check->service_charge,
            'total' => (float) $check->total,
            'amount_paid' => (float) $check->amount_paid,
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

    protected function assertEditable(HospitalityCheck $check): void
    {
        if (! in_array($check->status, ['open', 'held'], true)) {
            throw ValidationException::withMessages(['check' => ['This check can no longer be edited.']]);
        }
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
