<?php

namespace App\Services\Ai\Tools;

use App\Models\CustomerInvoice;
use App\Models\Sale;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Support\SalePaymentStatus;
use App\Support\SalesOrderReference;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Look up one sales order / shop invoice by number (S1537, 1537, AR-1537)
 * with payment lines and guidance for correcting a wrong payment.
 */
class GetOrderDetailsTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_order_details';
    }

    public function description(): string
    {
        return 'Look up one Centrix sales order / shop invoice by number and return status, customer, '
            .'totals, balance due, payment lines (tender, amount, reference, date), linked AR invoice, '
            .'and screens to open. In Centrix, "invoice 1537" / "S1537" means sales.order_num (display S1537). '
            .'Use for "check invoice S1537", "order 1537", "I wrongly paid invoice 1537", payment history on an order, '
            .'or opening a specific sale. Pass order_num or query (e.g. S1537, 1537, AR-1537). '
            .'Do NOT say Centrix cannot look up invoices by number — always call this tool first.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_num' => [
                    'type' => 'string',
                    'description' => 'Order / invoice reference: S1537, 1537, #S1537, or AR-1537.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Free text containing the order/invoice number when order_num is unknown.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }

        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $orgId = (int) $organization->id;
        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate)
            || $this->permissions->hasPermission($user, 'customers.view', $gate)
            || $this->permissions->hasPermission($user, 'accounting.ar.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view sales orders.',
                'screens' => [
                    ['label' => 'Sales orders', 'path' => '/sales/orders'],
                ],
            ];
        }

        if (! Schema::hasTable('sales')) {
            return [
                'error' => true,
                'message' => 'Sales orders are not available in this environment.',
                'screens' => [
                    ['label' => 'Sales orders', 'path' => '/sales/orders'],
                ],
            ];
        }

        $raw = trim((string) ($arguments['order_num'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($arguments['query'] ?? ''));
        }

        $orderNum = SalesOrderReference::parseOrderNum($raw);
        $sale = null;

        if ($orderNum !== null) {
            $sale = $this->findSaleByOrderNum($orgId, $orderNum);
        }

        // Fall back: AR invoice_number exact / like, or pos_order_num.
        if (! $sale && $raw !== '') {
            $sale = $this->findSaleByAlternateRef($orgId, $raw);
        }

        if (! $sale) {
            $display = SalesOrderReference::display($orderNum) ?? ($raw !== '' ? $raw : null);

            return [
                'error' => true,
                'message' => $display
                    ? "No sales order matched **{$display}**. Confirm the printed invoice/order number (S####) or search at /sales/orders."
                    : 'Provide an order or invoice number (e.g. S1537 or 1537).',
                'searched_for' => $raw !== '' ? $raw : null,
                'parsed_order_num' => $orderNum,
                'screens' => [
                    ['label' => 'Sales orders', 'path' => '/sales/orders'.($orderNum ? '?q=S'.$orderNum : '')],
                    ['label' => 'Unpaid debtors', 'path' => '/sales/shop-debtors/unpaid'],
                    ['label' => 'Partial debtors', 'path' => '/sales/shop-debtors/partial'],
                ],
            ];
        }

        return $this->presentSale($sale, $orgId);
    }

    protected function findSaleByOrderNum(int $orgId, int $orderNum): ?Sale
    {
        $q = Sale::query()
            ->where('organization_id', $orgId)
            ->where('order_num', $orderNum);
        if (Schema::hasColumn('sales', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return $q->first();
    }

    protected function findSaleByAlternateRef(int $orgId, string $raw): ?Sale
    {
        $term = trim($raw);
        if ($term === '') {
            return null;
        }

        if (Schema::hasTable('customer_invoices')) {
            $invoice = CustomerInvoice::query()
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($term) {
                    $q->where('invoice_number', $term);
                    if (preg_match('/^AR[-_]?\d+/i', $term)) {
                        $q->orWhere('invoice_number', 'like', $term.'%');
                    }
                })
                ->orderByDesc('id')
                ->first();

            if ($invoice && $invoice->sale_id) {
                $saleQ = Sale::query()
                    ->where('organization_id', $orgId)
                    ->where('id', (int) $invoice->sale_id);
                if (Schema::hasColumn('sales', 'deleted_at')) {
                    $saleQ->whereNull('deleted_at');
                }
                $sale = $saleQ->first();
                if ($sale) {
                    return $sale;
                }
            }
        }

        // POS ticket number (string) — only when the whole query looks like a ticket ref.
        if (preg_match('/^[A-Za-z0-9\-]{2,32}$/', $term) && ! preg_match('/^#?S0*\d+$/i', $term)) {
            $saleQ = Sale::query()
                ->where('organization_id', $orgId)
                ->where('pos_order_num', $term);
            if (Schema::hasColumn('sales', 'deleted_at')) {
                $saleQ->whereNull('deleted_at');
            }

            return $saleQ->orderByDesc('id')->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSale(Sale $sale, int $orgId): array
    {
        $sale->loadMissing(['customer', 'payments.paymentMethod', 'items', 'cashier']);

        $orderNum = (int) ($sale->order_num ?? 0);
        $display = SalesOrderReference::display($orderNum) ?? (string) $orderNum;
        $orderTotal = round((float) ($sale->order_total ?? 0), 2);
        $amountPaid = round((float) ($sale->amount_paid ?? 0), 2);
        $balanceDue = max(0, round($orderTotal - $amountPaid, 2));
        $paymentStatus = SalePaymentStatus::resolve(
            (string) ($sale->status ?? ''),
            $orderTotal,
            $amountPaid,
        );

        $payments = [];
        foreach ($sale->payments as $pay) {
            $method = $pay->paymentMethod;
            $payments[] = [
                'id' => (int) $pay->id,
                'amount' => round((float) ($pay->amount ?? 0), 2),
                'method_name' => $method?->method_name ?? $method?->method_code ?? null,
                'method_code' => $method?->method_code ?? null,
                'reference_number' => $pay->reference_number,
                'paid_at' => $pay->paid_at?->toIso8601String(),
            ];
        }

        $lines = [];
        foreach ($sale->items->take(40) as $item) {
            $lines[] = [
                'product_name' => $item->resolvedProductName(),
                'quantity' => (float) ($item->quantity ?? 0),
                'uom' => $item->uom,
                'unit_price' => round((float) ($item->selling_price ?? 0), 2),
                'amount' => round((float) ($item->amount ?? 0), 2),
            ];
        }

        $invoiceNumber = null;
        if (Schema::hasTable('customer_invoices')) {
            $invoiceNumber = CustomerInvoice::query()
                ->where('organization_id', $orgId)
                ->where('sale_id', $sale->id)
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->value('invoice_number');
        }

        $path = '/sales/orders/'.$sale->id;
        $overpaid = $amountPaid > $orderTotal + SalePaymentStatus::EPSILON;

        $correctionGuidance = [
            'Right customer, wrong amount or applied to the wrong order on the same customer — open the order, reverse or re-allocate the receipt from payment history, then re-apply the correct amount.',
            'Payment posted to the wrong customer entirely — reverse it on this order, then take the payment on the correct customer/order.',
            'Sale itself was wrong (items/qty) — raise a credit note at /sales/returns rather than editing the invoice.',
            'Money came in by bank or M-Pesa — after reversal, match the line in /accounting/bank-reconciliation.',
        ];

        $debtorQueue = match ($paymentStatus) {
            SalePaymentStatus::UNPAID => '/sales/shop-debtors/unpaid',
            SalePaymentStatus::PARTIAL => '/sales/shop-debtors/partial',
            default => null,
        };

        $screens = [
            ['label' => 'Open this order', 'path' => $path],
            ['label' => 'All sales orders', 'path' => '/sales/orders?q='.rawurlencode($display)],
        ];
        if ($debtorQueue) {
            $screens[] = ['label' => 'Debtors queue', 'path' => $debtorQueue];
        }
        $screens[] = ['label' => 'Credit notes / returns', 'path' => '/sales/returns'];
        $screens[] = ['label' => 'Bank reconciliation', 'path' => '/accounting/bank-reconciliation'];

        $directBits = [
            "{$display} — ".($sale->customerDisplayName()),
            'payment '.$paymentStatus,
            'total KES '.number_format($orderTotal, 2),
            'paid KES '.number_format($amountPaid, 2),
        ];
        if ($balanceDue > SalePaymentStatus::EPSILON) {
            $directBits[] = 'balance due KES '.number_format($balanceDue, 2);
        } elseif ($overpaid) {
            $directBits[] = 'overpaid (paid exceeds total)';
        }

        return [
            'order_num' => $orderNum,
            'order_num_display' => $display,
            'sale_id' => (int) $sale->id,
            'status' => $sale->status,
            'payment_status' => $paymentStatus,
            'is_credit_sale' => (bool) ($sale->is_credit_sale ?? false),
            'customer_num' => $sale->customer_num,
            'customer_name' => $sale->customerDisplayName(),
            'cashier_name' => trim((string) ($sale->cashier?->name ?? $sale->cashier?->username ?? '')) ?: null,
            'order_total' => $orderTotal,
            'amount_paid' => $amountPaid,
            'balance_due' => $balanceDue,
            'overpaid' => $overpaid,
            'order_discount' => round((float) ($sale->order_discount ?? 0), 2),
            'total_vat' => round((float) ($sale->total_vat ?? 0), 2),
            'channel' => $sale->channel,
            'completed_at' => $sale->completed_at?->toIso8601String(),
            'created_at' => $sale->created_at?->toIso8601String(),
            'invoice_number' => $invoiceNumber,
            'pos_order_num' => $sale->pos_order_num,
            'payments' => $payments,
            'payment_count' => count($payments),
            'line_items' => $lines,
            'line_count' => $sale->items->count(),
            'correction_guidance' => $correctionGuidance,
            'path' => $path,
            'screens' => $screens,
            'direct_answer' => implode('; ', $directBits).'.',
            'tip' => 'In Centrix, invoice number and order number are the same S-prefix reference (e.g. S1537). '
                .'Never say you cannot look up an invoice/order by number — this tool result is that lookup. '
                .'Lead with order_num_display, customer_name, payment_status, order_total, amount_paid, balance_due. '
                .'List payments (method, amount, reference, date) when present. '
                .'For wrongly paid / wrong payment / reverse payment: use correction_guidance — Centrix does not reverse payments via chat; '
                .'guide the user to the order payment history at path, then /sales/returns or /accounting/bank-reconciliation as needed. '
                .'Offer get_customer_statement with customer_name if they want the full customer balance next.',
        ];
    }
}
