<?php

namespace App\Services\Notifications;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\Organization;
use App\Models\Sale;

class CustomerNotificationService
{
    public const SCOPE_OPTIONS = ['all', 'mobile', 'debtors', 'route_orders'];

    public function __construct(protected CustomerNotificationDispatcher $dispatcher) {}

    public function notifyOrderPlaced(Sale $sale, Organization $organization): void
    {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        if (empty($settings['notify_on_order_placed'])) {
            return;
        }

        $scopes = NotificationSettingsResolver::normalizeScopes(
            $settings['order_placed_scope'] ?? 'all',
            'all',
        );
        if (! $this->matchesAnyScope($sale, $scopes)) {
            return;
        }

        $this->dispatcher->notifySaleCustomer(
            $organization,
            $sale,
            $settings['order_placed_sms_template'],
            $settings['order_placed_email_template'],
            'Order {order_num} confirmation',
            $this->saleTemplateVars($sale),
        );
    }

    public function notifyDebtorPayment(Sale $sale, Organization $organization, float $amount): void
    {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        if (empty($settings['notify_on_debtor_payment'])) {
            return;
        }

        $scopes = NotificationSettingsResolver::normalizeScopes(
            $settings['debtor_payment_scope'] ?? 'debtors',
            'debtors',
        );
        if (! $this->matchesAnyScope($sale, $scopes)) {
            return;
        }

        $vars = array_merge($this->saleTemplateVars($sale), [
            'amount' => $this->formatMoney($amount),
        ]);

        $this->dispatcher->notifySaleCustomer(
            $organization,
            $sale,
            $settings['debtor_payment_sms_template'],
            $settings['debtor_payment_email_template'],
            'Payment received for order {order_num}',
            $vars,
        );
    }

    public function notifyDebtReminder(Sale $sale, Organization $organization): bool
    {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        if (empty($settings['notify_on_debt_reminder'])) {
            return false;
        }

        $scopes = NotificationSettingsResolver::normalizeScopes(
            $settings['debt_reminder_scope'] ?? 'debtors',
            'debtors',
        );
        if (! $this->matchesAnyScope($sale, $scopes)) {
            return false;
        }

        $balance = max(0, (float) $sale->order_total - (float) $sale->amount_paid);
        if ($balance <= 0.01) {
            return false;
        }

        $vars = array_merge($this->saleTemplateVars($sale), [
            'days_overdue' => (string) $this->unpaidAgeDays($sale),
        ]);

        $this->dispatcher->notifySaleCustomer(
            $organization,
            $sale,
            $settings['debt_reminder_sms_template']
                ?: 'Reminder: order {order_num} still has an unpaid balance of KES {balance_due}. Please arrange payment. Thank you.',
            $settings['debt_reminder_email_template'] ?? '',
            'Payment reminder for order {order_num}',
            $vars,
        );

        return true;
    }

    /**
     * @param  list<string>  $scopes
     */
    public function saleMatchesNotificationScopes(Sale $sale, array $scopes): bool
    {
        return $this->matchesAnyScope($sale, $scopes);
    }

    public function unpaidAgeDays(Sale $sale): int
    {
        $anchor = $sale->completed_at ?? $sale->created_at;
        if (! $anchor) {
            return 0;
        }

        return max(0, (int) $anchor->diffInDays(now()));
    }

    public function notifyInvoicePayment(CustomerInvoicePayment $payment, Organization $organization): void
    {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        if (empty($settings['notify_on_debtor_payment'])) {
            return;
        }

        $invoice = CustomerInvoice::query()->find($payment->customer_invoice_id);
        $sale = $invoice?->sale_id ? Sale::query()->find($invoice->sale_id) : null;

        if ($sale) {
            $this->notifyDebtorPayment($sale, $organization, (float) $payment->amount_paid);

            return;
        }

        if (! $payment->customer_num) {
            return;
        }

        $scopes = NotificationSettingsResolver::normalizeScopes(
            $settings['debtor_payment_scope'] ?? 'debtors',
            'debtors',
        );

        // Invoice-only payments (no linked sale): allow when all/debtors; skip mobile/route-only filters
        // unless the customer has a route for route_orders.
        if (! in_array('all', $scopes, true) && ! in_array('debtors', $scopes, true)) {
            if (in_array('route_orders', $scopes, true)) {
                $customer = Customer::query()
                    ->where('customer_num', $payment->customer_num)
                    ->where('organization_id', $payment->organization_id)
                    ->first();
                if (! $customer?->route_id) {
                    return;
                }
            } else {
                return;
            }
        }

        $customer = Customer::query()
            ->where('customer_num', $payment->customer_num)
            ->where('organization_id', $payment->organization_id)
            ->first();
        if (! $customer) {
            return;
        }

        $vars = [
            'order_num' => $invoice?->invoice_number ?? '—',
            'order_total' => $this->formatMoney($invoice?->invoice_total ?? 0),
            'amount_paid' => $this->formatMoney($payment->amount_paid),
            'amount' => $this->formatMoney($payment->amount_paid),
            'balance_due' => $this->formatMoney(max(0, (float) ($invoice?->invoice_total ?? 0) - (float) ($invoice?->amount_paid ?? 0))),
        ];

        $this->dispatcher->notifyCustomerContact(
            $organization,
            $customer->phone_number ? trim((string) $customer->phone_number) : null,
            $customer->email ? trim((string) $customer->email) : null,
            $settings['debtor_payment_sms_template'],
            $settings['debtor_payment_email_template'],
            'Payment received for order {order_num}',
            $vars,
        );
    }

    /**
     * @param  list<string>  $scopes
     */
    protected function matchesAnyScope(Sale $sale, array $scopes): bool
    {
        if ($scopes === [] || in_array('all', $scopes, true)) {
            return true;
        }

        foreach ($scopes as $scope) {
            if ($this->matchesScope($sale, $scope)) {
                return true;
            }
        }

        return false;
    }

    protected function matchesScope(Sale $sale, string $scope): bool
    {
        return match ($scope) {
            'all' => true,
            'mobile' => $this->isMobileSale($sale),
            'debtors' => $this->isDebtorSale($sale),
            'route_orders' => ! empty($sale->route_id),
            default => false,
        };
    }

    protected function isMobileSale(Sale $sale): bool
    {
        if (strtolower(trim((string) ($sale->channel ?? ''))) === 'mobile') {
            return true;
        }

        return strtolower(trim((string) ($sale->order_source ?? ''))) === 'mobile';
    }

    protected function isDebtorSale(Sale $sale): bool
    {
        if ($sale->is_credit_sale) {
            return true;
        }

        $balance = max(0, (float) $sale->order_total - (float) $sale->amount_paid);
        if ($balance > 0.01 && $sale->customer_num) {
            return true;
        }

        if (! $sale->customer_num) {
            return false;
        }

        $customer = Customer::query()
            ->where('customer_num', $sale->customer_num)
            ->where('organization_id', $sale->organization_id)
            ->first();
        if (! $customer) {
            return false;
        }

        return (float) ($customer->credit_limit ?? 0) > 0
            || trim((string) ($customer->terms_of_payment ?? '')) !== '';
    }

    /** @return array<string, string> */
    protected function saleTemplateVars(Sale $sale): array
    {
        $balance = max(0, (float) $sale->order_total - (float) $sale->amount_paid);

        return [
            'order_num' => (string) ($sale->order_num ?? $sale->id),
            'order_total' => $this->formatMoney($sale->order_total),
            'amount_paid' => $this->formatMoney($sale->amount_paid),
            'balance_due' => $this->formatMoney($balance),
        ];
    }

    protected function formatMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }
}
