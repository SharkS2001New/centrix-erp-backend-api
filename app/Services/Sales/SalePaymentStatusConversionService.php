<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Erp\SalePaymentColumnMapper;
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administrative Convert to Paid / Convert to Unpaid (org-configured stages).
 * Distinct from Collect payment — does not require a till tender entry from the cashier.
 */
class SalePaymentStatusConversionService
{
    public function __construct(protected ErpContext $erp) {}

    public function convertToPaid(Sale $sale, User $user, bool $skipStatusGate = false): Sale
    {
        $notifiedAmount = 0.0;

        $sale = DB::transaction(function () use ($sale, $user, $skipStatusGate, &$notifiedAmount) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $gate = $this->erp->gateForUser($user);
            $workflow = OrderWorkflowService::forGate($gate);

            if (! $skipStatusGate && ! $workflow->canConvertToPaidForOrder(
                (string) $sale->status,
                $sale->channel ?: null,
                (string) ($sale->payment_status ?? ''),
            )) {
                throw ValidationException::withMessages([
                    'payment_status' => ['Convert to paid is not allowed for this order.'],
                ]);
            }

            $total = round((float) $sale->order_total, 2);
            $alreadyPaid = round((float) $sale->amount_paid, 2);
            if ($alreadyPaid + 0.01 >= $total && $total > 0) {
                throw ValidationException::withMessages([
                    'payment_status' => ['This order is already fully paid.'],
                ]);
            }

            $balance = round(max(0, $total - $alreadyPaid), 2);
            $notifiedAmount = $balance;
            $method = $this->resolveConversionPaymentMethod((int) $sale->organization_id, (string) ($sale->payment_method_code ?? 'CASH'));

            if ($balance > 0.009 && $method) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'payment_method_id' => $method->id,
                    'amount' => $balance,
                    'reference_number' => 'Converted to paid',
                    'paid_at' => now(),
                ]);
                SalePaymentColumnMapper::applyToSale($sale->fresh(), (string) $method->method_code, $balance);
            }

            $newPaid = $total;
            $orderStatus = $workflow->resolveStatusAfterPayment(
                (string) $sale->channel,
                (string) $sale->status,
                $newPaid,
                $total,
                (bool) $sale->is_credit_sale,
                (string) ($method?->method_code ?? $sale->payment_method_code ?? 'CASH'),
                true,
            );

            $updates = [
                'amount_paid' => $newPaid,
                'payment_status' => 'paid',
            ];

            if ($sale->status !== 'cancelled' && $sale->status !== 'held') {
                $updates['status'] = $orderStatus;
                if ($workflow->isTerminalStatus($orderStatus, (string) $sale->channel)) {
                    $updates['completed_at'] = $sale->completed_at ?? now();
                }
            }

            $sale->update($updates);
            $sale = $sale->fresh();

            if ($sale->customer_num) {
                app(CustomerInvoiceService::class)->ensureForSale(
                    $sale,
                    $user,
                    $total,
                    $newPaid,
                );
            }

            return $sale;
        });

        if ($notifiedAmount > 0.009) {
            try {
                $organization = $sale->organization
                    ?? \App\Models\Organization::query()->find($sale->organization_id);
                if ($organization) {
                    app(CustomerNotificationService::class)->notifyDebtorPayment(
                        $sale,
                        $organization,
                        $notifiedAmount,
                    );
                }
            } catch (\Throwable) {
                // Customer SMS/email must not roll back payment conversion.
            }
        }

        return $sale;
    }

    public function convertToUnpaid(Sale $sale, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $user) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $gate = $this->erp->gateForUser($user);
            $workflow = OrderWorkflowService::forGate($gate);

            if (! $workflow->canConvertToUnpaidForOrder(
                (string) $sale->status,
                $sale->channel ?: null,
                (string) ($sale->payment_status ?? ''),
            )) {
                throw ValidationException::withMessages([
                    'payment_status' => ['Convert to unpaid is not allowed for this order.'],
                ]);
            }

            $alreadyPaid = round((float) $sale->amount_paid, 2);
            if ($alreadyPaid <= 0.01) {
                throw ValidationException::withMessages([
                    'payment_status' => ['This order is already unpaid.'],
                ]);
            }

            SalePayment::query()->where('sale_id', $sale->id)->delete();
            SalePaymentColumnMapper::replaceFromMethodMap($sale, []);

            $updates = [
                'amount_paid' => 0,
                'payment_status' => 'unpaid',
            ];

            // Shop receivable: mark credit when the sale is tied to a regular/debtor customer.
            if ($sale->customer_num) {
                $customer = Customer::query()
                    ->where('organization_id', $sale->organization_id)
                    ->where('customer_num', $sale->customer_num)
                    ->first();
                $customerType = strtolower(trim((string) ($customer?->customer_type ?? '')));
                if ($customerType === '' || in_array($customerType, ['regular', 'debtor'], true)) {
                    $updates['is_credit_sale'] = 1;
                    $updates['payment_method_code'] = 'CREDIT';
                }
            }

            if (
                $sale->status !== 'cancelled'
                && $sale->status !== 'held'
                && $workflow->isPaymentWorkflowStatus((string) $sale->status, (string) $sale->channel)
            ) {
                $updates['status'] = $workflow->resolveSaveStatus((string) $sale->channel);
            }

            $sale->update($updates);
            $sale = $sale->fresh();

            if ($sale->customer_num) {
                app(CustomerInvoiceService::class)->ensureForSale(
                    $sale,
                    $user,
                    (float) $sale->order_total,
                    0,
                );
            }

            return $sale;
        });
    }

    protected function resolveConversionPaymentMethod(int $organizationId, string $preferredCode): ?PaymentMethod
    {
        $code = strtoupper(trim($preferredCode)) ?: 'CASH';
        $method = PaymentMethod::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('UPPER(method_code) = ?', [$code])
            ->first();

        if ($method) {
            return $method;
        }

        return PaymentMethod::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('UPPER(method_code) = ?', ['CASH'])
            ->first()
            ?? PaymentMethod::query()->where('organization_id', $organizationId)->first();
    }
}
