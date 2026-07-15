<?php

namespace App\Services\Notifications;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\Organization;
use App\Models\Sale;

class CustomerNotificationService
{
    public function __construct(protected CustomerNotificationDispatcher $dispatcher) {}

    public function notifyOrderPlaced(Sale $sale, Organization $organization): void
    {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        if (empty($settings['notify_on_order_placed'])) {
            return;
        }

        if (! $this->matchesScope($sale, (string) ($settings['order_placed_scope'] ?? 'all'))) {
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

        if (! $this->matchesScope($sale, (string) ($settings['debtor_payment_scope'] ?? 'debtors'))) {
            return;
        }

        $scope = (string) ($settings['debtor_payment_scope'] ?? 'debtors');
        if ($scope === 'debtors' && ! $this->isDebtorSale($sale)) {
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

        $customer = Customer::query()
            ->where('customer_num', $payment->customer_num)
            ->where('organization_id', $payment->organization_id)
            ->first();
        if (! $customer) {
            return;
        }

        if (($settings['debtor_payment_scope'] ?? 'debtors') === 'route_orders' && ! $customer->route_id) {
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

    protected function matchesScope(Sale $sale, string $scope): bool
    {
        return match ($scope) {
            'debtors' => $this->isDebtorSale($sale),
            'route_orders' => ! empty($sale->route_id),
            default => true,
        };
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
