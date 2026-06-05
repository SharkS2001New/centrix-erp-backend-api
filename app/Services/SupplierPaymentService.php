<?php

namespace App\Services;

use App\Models\LpoMst;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\LpoModuleService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SupplierPaymentService
{
    public function __construct(
        protected SupplierBalanceService $supplierBalances,
        protected LpoModuleService $lpoModule,
    ) {}

    /**
     * Record a payment (full or partial) against supplier AP or a specific LPO.
     *
     * @return array{payment: SupplierPayment, balance_before: float, balance_after: float, is_partial: bool}
     */
    public function record(array $data): array
    {
        $amount = round((float) ($data['amount_paid'] ?? 0), 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $manual = ! empty($data['manual_amount']);

        return DB::transaction(function () use ($data, $amount, $manual) {
            $supplier = Supplier::query()
                ->whereNull('deleted_at')
                ->findOrFail((int) $data['supplier_id']);

            $lpo = null;
            if (! empty($data['lpo_no'])) {
                $lpo = LpoMst::query()
                    ->where('lpo_no', (int) $data['lpo_no'])
                    ->where('supplier_id', $supplier->id)
                    ->whereNull('deleted_at')
                    ->firstOrFail();
            }

            if ($manual && ! empty($data['declared_payable'])) {
                $declared = round((float) $data['declared_payable'], 2);
                if ($lpo) {
                    $lpo->update([
                        'total_amount' => $declared,
                        'net_amount' => $declared,
                    ]);
                    $lpo = $lpo->fresh();
                }
            }

            if ($manual && $lpo) {
                $this->supplierBalances->recalculate($supplier->id);
                $supplier = $supplier->fresh();
            }

            $balanceBefore = $this->resolvePayableBase($supplier, $lpo, $data, $manual);

            if ($lpo && ! $manual) {
                $this->lpoModule->assertCanPay($lpo);
            }

            if (! $manual) {
                if ($balanceBefore <= 0 && $lpo) {
                    throw new InvalidArgumentException('This LPO has no balance due.');
                }

                if ($amount > $balanceBefore + 0.01) {
                    $label = $lpo ? "LPO #{$lpo->lpo_no}" : 'supplier account';
                    $hint = $lpo
                        ? ' Payment cannot exceed the value of items already received (minus returns).'
                        : '';
                    throw new InvalidArgumentException(
                        "Payment exceeds amount owing on {$label} (KES " . number_format($balanceBefore, 2) . ").{$hint} Use a partial amount or manual payable if appropriate.",
                    );
                }
            } elseif (! empty($data['declared_payable']) && $amount > $balanceBefore + 0.01) {
                throw new InvalidArgumentException(
                    'Payment exceeds the payable amount you entered (KES ' . number_format($balanceBefore, 2) . ').',
                );
            }

            $snapshot = array_key_exists('amount_due_snapshot', $data) && $data['amount_due_snapshot'] !== null
                ? round((float) $data['amount_due_snapshot'], 2)
                : $balanceBefore;

            $isPartial = $balanceBefore > 0 && $amount + 0.01 < $balanceBefore;

            $payment = SupplierPayment::create([
                'supplier_id' => $supplier->id,
                'lpo_no' => $lpo?->lpo_no,
                'lpo_supplier_invoice_id' => $data['lpo_supplier_invoice_id'] ?? null,
                'payment_method_id' => (int) $data['payment_method_id'],
                'amount_paid' => $amount,
                'amount_due_snapshot' => $snapshot > 0 ? $snapshot : null,
                'reference_number' => $data['reference_number'] ?? null,
                'cheque_number' => $data['cheque_number'] ?? null,
                'date_paid' => $data['date_paid'],
                'paid_by' => (int) $data['paid_by'],
                'organization_id' => (int) $data['organization_id'],
                'notes' => $this->appendManualNote($data['notes'] ?? null, $manual),
            ]);

            if ($lpo) {
                $this->syncLpoClearedState($lpo->fresh());
            }

            $supplier = $this->supplierBalances->recalculate($supplier->id);
            $balanceAfter = $lpo
                ? $this->balanceDue($supplier, $lpo->fresh())
                : max(0, round((float) $supplier->current_balance, 2));

            return [
                'payment' => $payment->fresh(),
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'is_partial' => $isPartial,
            ];
        });
    }

    protected function resolvePayableBase(Supplier $supplier, ?LpoMst $lpo, array $data, bool $manual): float
    {
        if ($manual && ! empty($data['declared_payable'])) {
            $declared = round((float) $data['declared_payable'], 2);
            if ($lpo) {
                $paid = (float) SupplierPayment::query()
                    ->where('lpo_no', $lpo->lpo_no)
                    ->sum('amount_paid');

                return round(max(0, $declared - $paid), 2);
            }

            return $declared;
        }

        return $this->balanceDue($supplier, $lpo);
    }

    protected function appendManualNote(?string $notes, bool $manual): ?string
    {
        if (! $manual) {
            return $notes;
        }
        $tag = '[Manual payable]';
        if ($notes && str_contains($notes, $tag)) {
            return $notes;
        }

        return trim($tag . ($notes ? " {$notes}" : ''));
    }

    public function balanceDue(Supplier $supplier, ?LpoMst $lpo = null): float
    {
        if ($lpo) {
            $paid = (float) SupplierPayment::query()
                ->where('lpo_no', $lpo->lpo_no)
                ->sum('amount_paid');

            return $this->lpoModule->payableBalanceDue($lpo, $paid);
        }

        return round(max(0, (float) $supplier->current_balance), 2);
    }

    protected function syncLpoClearedState(LpoMst $lpo): void
    {
        app(LpoModuleService::class)->syncClearedStatus((int) $lpo->lpo_no);
    }
}
