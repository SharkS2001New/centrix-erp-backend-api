<?php

namespace App\Services\Payments;

use App\Models\MpesaIncomingPayment;
use App\Models\MpesaStkRequest;
use App\Models\SalePayment;
use Illuminate\Support\Facades\Schema;

class PaymentTransactionService
{
    /**
     * Unified read model over existing payment transaction sources.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listForOrganization(int $organizationId, array $filters = []): array
    {
        $limit = min(200, max(1, (int) ($filters['limit'] ?? 50)));
        $rows = [];

        if (Schema::hasTable('mpesa_stk_requests')) {
            $stkQuery = MpesaStkRequest::query()
                ->where('organization_id', $organizationId)
                ->orderByDesc('id')
                ->limit($limit);

            if (! empty($filters['status'])) {
                $stkQuery->where('status', (string) $filters['status']);
            }

            foreach ($stkQuery->get() as $row) {
                $rows[] = [
                    'id' => 'stk-' . $row->id,
                    'source' => 'mpesa_stk',
                    'provider' => 'mpesa',
                    'provider_transaction_id' => $row->checkout_request_id,
                    'centrix_reference' => $row->cart_id ? 'POS-CART-' . $row->cart_id : null,
                    'amount' => (float) ($row->amount ?? 0),
                    'currency' => 'KES',
                    'phone_number' => $row->phone_number,
                    'mpesa_receipt' => $row->transaction_id,
                    'status' => strtoupper((string) ($row->status ?? 'pending')),
                    'transaction_date' => $row->created_at?->toDateString(),
                    'initiated_at' => $row->created_at?->toIso8601String(),
                    'completed_at' => $row->completed_at?->toIso8601String() ?? $row->updated_at?->toIso8601String(),
                    'pos_sale_id' => null,
                    'metadata' => [
                        'cart_id' => $row->cart_id,
                        'merchant_request_id' => $row->merchant_request_id,
                    ],
                ];
            }
        }

        if (Schema::hasTable('mpesa_incoming_payments')) {
            $incomingQuery = MpesaIncomingPayment::query()
                ->where('organization_id', $organizationId)
                ->orderByDesc('id')
                ->limit($limit);

            foreach ($incomingQuery->get() as $row) {
                $rows[] = [
                    'id' => 'c2b-' . $row->id,
                    'source' => 'mpesa_c2b',
                    'provider' => 'mpesa',
                    'provider_transaction_id' => $row->transaction_id,
                    'centrix_reference' => $row->bill_ref_number,
                    'amount' => (float) ($row->amount ?? 0),
                    'currency' => 'KES',
                    'phone_number' => $row->phone_number,
                    'mpesa_receipt' => $row->transaction_id,
                    'status' => strtoupper((string) ($row->reconciliation_status ?? $row->status ?? 'pending')),
                    'transaction_date' => $row->received_at?->toDateString() ?? $row->created_at?->toDateString(),
                    'initiated_at' => $row->created_at?->toIso8601String(),
                    'completed_at' => $row->applied_at?->toIso8601String() ?? $row->updated_at?->toIso8601String(),
                    'pos_sale_id' => $row->applied_sale_id,
                    'metadata' => [
                        'business_short_code' => $row->business_short_code,
                    ],
                ];
            }
        }

        if (Schema::hasTable('sale_payments')) {
            $salePayments = SalePayment::query()
                ->whereHas('sale', fn ($q) => $q->where('organization_id', $organizationId))
                ->with(['sale:id,invoice_number', 'paymentMethod:id,method_code,method_name'])
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            foreach ($salePayments as $row) {
                $methodCode = strtoupper((string) ($row->paymentMethod?->method_code ?? ''));
                $rows[] = [
                    'id' => 'sale-payment-' . $row->id,
                    'source' => 'sale_payment',
                    'provider' => strtolower($methodCode ?: 'cash'),
                    'provider_transaction_id' => $row->reference_number,
                    'centrix_reference' => $row->sale?->invoice_number,
                    'amount' => (float) ($row->amount ?? 0),
                    'currency' => 'KES',
                    'phone_number' => null,
                    'mpesa_receipt' => $methodCode === 'MPESA' ? $row->reference_number : null,
                    'status' => 'SUCCESS',
                    'transaction_date' => $row->paid_at?->toDateString(),
                    'initiated_at' => $row->created_at?->toIso8601String(),
                    'completed_at' => $row->paid_at?->toIso8601String(),
                    'pos_sale_id' => $row->sale_id,
                    'metadata' => [
                        'payment_method' => $row->paymentMethod?->method_name,
                    ],
                ];
            }
        }

        usort($rows, fn ($a, $b) => strcmp((string) ($b['initiated_at'] ?? ''), (string) ($a['initiated_at'] ?? '')));

        return [
            'data' => array_slice($rows, 0, $limit),
            'meta' => [
                'total' => count($rows),
                'limit' => $limit,
            ],
        ];
    }
}
