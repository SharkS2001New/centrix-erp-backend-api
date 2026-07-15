<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use InvalidArgumentException;

class CustomerCreditLimit
{
    public static function outstandingReceivable(Customer $customer): float
    {
        return (float) CustomerInvoice::query()
            ->where('organization_id', $customer->organization_id)
            ->where('customer_num', $customer->customer_num)
            ->whereIn('payment_status', [0, 1])
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(invoice_total - amount_paid), 0) as total')
            ->value('total');
    }

    /** Fast path for display / bot prompts — uses denormalized customer balance. */
    public static function displayOutstanding(Customer $customer): float
    {
        return (float) ($customer->current_balance ?? 0);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertCreditSaleAllowed(
        ?int $customerNum,
        float $creditAmount,
        bool $isCredit,
        ?int $organizationId = null,
    ): void {
        if (! $isCredit) {
            return;
        }

        if (! $customerNum) {
            throw new InvalidArgumentException(
                'Credit sales require a registered customer. Walk-in sales cannot be charged to accounts receivable.',
            );
        }

        if ($creditAmount <= 0.009) {
            return;
        }

        $query = Customer::query()
            ->where('customer_num', $customerNum)
            ->whereNull('deleted_at')
            ->lockForUpdate();

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $customer = $query->first();

        if (! $customer) {
            throw new InvalidArgumentException('Select a valid registered customer for credit.');
        }

        $limit = (float) $customer->credit_limit;
        if ($limit <= 0) {
            return;
        }

        $outstanding = self::outstandingReceivable($customer);
        $available = $limit - $outstanding;

        if ($creditAmount > $available + 0.0001) {
            throw new InvalidArgumentException(sprintf(
                'Credit limit exceeded for %s. Available credit is KES %s (limit KES %s, outstanding KES %s).',
                $customer->customer_name,
                number_format(max(0, $available), 2),
                number_format($limit, 2),
                number_format($outstanding, 2),
            ));
        }
    }
}
