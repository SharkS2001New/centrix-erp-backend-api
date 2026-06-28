<?php

namespace App\Services;

use App\Models\LpoMst;
use App\Models\LpoStatus;
use App\Models\LpoSupplierInvoice;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnDocument;
use App\Models\Uom;
use App\Models\User;
use App\Services\Purchasing\LpoWorkflowService;
use App\Services\Purchasing\SupplierReturnDocumentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LpoModuleService
{
    public const STATUS_AWAITING_RECEIVE = 3;

    public function formatPoNumber(int $lpoNo, $orderDate = null): string
    {
        $year = $orderDate
            ? (int) date('Y', strtotime((string) $orderDate))
            : (int) date('Y');

        return sprintf('LPO-%d-%04d', $year, $lpoNo);
    }

    public function mapListRow(LpoMst $lpo, ?int $organizationId = null): array
    {
        $status = LpoStatus::query()->find($lpo->lpo_status_code);
        $creator = $lpo->created_by ? User::query()->find($lpo->created_by) : null;
        $statusCode = (int) ($lpo->lpo_status_code ?? 0);
        $orderDate = $lpo->created_at ?? $lpo->sent_at;
        $canEdit = $statusCode < self::STATUS_AWAITING_RECEIVE;
        $paymentsTotal = $this->paymentsTotal((int) $lpo->lpo_no);
        $netAmount = (float) ($lpo->net_amount ?? $lpo->total_amount ?? 0);

        return [
            'lpo_no' => (int) $lpo->lpo_no,
            'po_number' => $this->formatPoNumber((int) $lpo->lpo_no, $orderDate),
            'supplier_id' => (int) $lpo->supplier_id,
            'supplier_name' => $lpo->supplier?->supplier_name,
            'reference_number' => $lpo->reference_number,
            'order_date' => $orderDate,
            'created_at' => $lpo->created_at,
            'due_date' => $lpo->due_date,
            'lpo_status_code' => $statusCode,
            'status_name' => $status?->status_name,
            'cleared_flag' => (int) ($lpo->cleared_flag ?? 0),
            'total_amount' => (float) ($lpo->total_amount ?? 0),
            'vat_amount' => (float) ($lpo->vat_amount ?? 0),
            'net_amount' => (float) ($lpo->net_amount ?? $lpo->total_amount ?? 0),
            'created_by_name' => $creator?->full_name ?? $creator?->username,
            'can_edit' => $canEdit,
            'can_delete' => $canEdit,
            'amount_paid' => round($paymentsTotal, 2),
            'balance_due' => round(max(0, $netAmount - $paymentsTotal), 2),
            'workflow_actions' => app(\App\Services\Purchasing\LpoWorkflowService::class)->workflowActions($lpo, $organizationId),
        ];
    }

    public function summary(int $lpoNo, ?int $organizationId = null): array
    {
        $lpo = LpoMst::query()
            ->with('supplier')
            ->whereNull('deleted_at')
            ->where('lpo_no', $lpoNo)
            ->firstOrFail();

        $settings = \App\Services\Purchasing\ProcurementSettingsResolver::forOrganizationId($organizationId);
        $defaultReceiveLocation = $settings['default_receive_location'] ?? 'store';

        $status = LpoStatus::query()->find($lpo->lpo_status_code);
        $creator = $lpo->created_by ? User::query()->find($lpo->created_by) : null;

        $lines = LpoTxn::query()->where('lpo_no', $lpoNo)->orderBy('id')->get();
        $products = Product::query()
            ->with('unit')
            ->whereIn('product_code', $lines->pluck('product_code')->filter()->unique())
            ->get()
            ->keyBy('product_code');

        $returnedByProduct = $this->returnedQtyByProduct($lpoNo, (int) $lpo->supplier_id, $lines);
        $lineRows = $lines->map(fn (LpoTxn $txn) => $this->mapLine($txn, $products, $returnedByProduct, $defaultReceiveLocation));

        $paymentsTotal = $this->paymentsTotal($lpoNo);
        $receivedPayable = $this->receivedPayableTotal($lineRows);
        $returnCredit = $this->returnCreditTotal($lineRows);
        $netAmount = (float) ($lpo->net_amount ?? $lpo->total_amount ?? 0);
        $payableBalance = max(0, $receivedPayable - $paymentsTotal);

        $itemsFullyReturned = $lineRows->isNotEmpty()
            && $lineRows->every(fn (array $line) => ($line['receive_status'] ?? '') === 'fully_returned');

        $lpoPayload = [
            'lpo_no' => (int) $lpo->lpo_no,
            'po_number' => $this->formatPoNumber((int) $lpo->lpo_no, $lpo->created_at ?? $lpo->sent_at),
            'supplier_id' => (int) $lpo->supplier_id,
            'supplier_name' => $lpo->supplier?->supplier_name,
            'reference_number' => $lpo->reference_number,
            'due_date' => $lpo->due_date,
            'delivery_address' => $lpo->delivery_address,
            'terms' => $lpo->terms,
            'instructions' => $lpo->instructions,
            'lpo_status_code' => (int) ($lpo->lpo_status_code ?? 0),
            'status_name' => $status?->status_name,
            'cleared_flag' => (int) ($lpo->cleared_flag ?? 0),
            'total_amount' => (float) ($lpo->total_amount ?? 0),
            'vat_amount' => (float) ($lpo->vat_amount ?? 0),
            'net_amount' => $netAmount,
            'subtotal' => max(0, $netAmount - (float) ($lpo->vat_amount ?? 0)),
            'order_date' => $lpo->created_at ?? $lpo->sent_at,
            'created_at' => $lpo->created_at,
            'sent_at' => $lpo->sent_at,
            'created_by' => $lpo->created_by,
            'created_by_name' => $creator?->full_name ?? $creator?->username,
            'supplier_invoice_no' => $lpo->supplier_invoice_no,
            'can_edit' => (int) ($lpo->lpo_status_code ?? 0) < self::STATUS_AWAITING_RECEIVE,
            'can_delete' => (int) ($lpo->lpo_status_code ?? 0) < self::STATUS_AWAITING_RECEIVE,
            'can_pay' => $receivedPayable > 0,
            'can_receive' => ! $itemsFullyReturned
                && (int) ($lpo->lpo_status_code ?? 0) >= 2
                && (int) ($lpo->lpo_status_code ?? 0) < 5,
            'can_create_return' => ! $itemsFullyReturned
                && (int) ($lpo->lpo_status_code ?? 0) >= self::STATUS_AWAITING_RECEIVE
                && $lineRows->contains(fn (array $line) => (float) ($line['max_return_qty'] ?? 0) > 0),
            'payment_status' => $this->paymentStatus($paymentsTotal, $receivedPayable),
            'received_payable_total' => round($receivedPayable, 2),
            'return_credit_total' => round($returnCredit, 2),
            'adjusted_net_amount' => round(max(0, $netAmount - $returnCredit), 2),
            'items_fully_returned_to_supplier' => $itemsFullyReturned,
            'amount_paid' => round($paymentsTotal, 2),
            'balance_due' => round($payableBalance, 2),
            'workflow_actions' => app(\App\Services\Purchasing\LpoWorkflowService::class)
                ->workflowActions($lpo, $organizationId),
            'supplier_email' => $lpo->supplier?->email,
            'supplier_phone' => $lpo->supplier?->phone ?? $lpo->supplier?->alternate_phone,
            'default_receive_location' => $defaultReceiveLocation,
        ];

        return [
            'lpo' => $lpoPayload,
            'lines' => $lineRows->values()->all(),
            'supplier_invoices' => $this->supplierInvoices($lpoNo),
            'supplier_returns' => $this->supplierReturns($lpoNo, (int) $lpo->supplier_id, $lines),
            'payments_total' => round($paymentsTotal, 2),
            'balance_due' => round($payableBalance, 2),
        ];
    }

    protected function mapLine(LpoTxn $txn, Collection $products, array $returnedByProduct, string $defaultReceiveLocation = 'store'): array
    {
        $product = $products->get($txn->product_code);
        $uom = $product?->unit;
        $ordered = (float) ($txn->ordered_qty ?? 0);
        $received = (float) ($txn->received_qty ?? 0);
        $returned = (float) ($returnedByProduct[$txn->product_code] ?? 0);
        $openReturn = max(0, min($received, $ordered) - $returned);

        return [
            'id' => (int) $txn->id,
            'lpo_no' => (int) $txn->lpo_no,
            'product_code' => $txn->product_code,
            'product_name' => $product?->product_name ?? $txn->product_code,
            'ordered_qty' => $ordered,
            'received_qty' => $received,
            'returned_qty' => $returned,
            'committed_return_qty' => $returned,
            'max_return_qty' => $openReturn,
            'cost_price' => (float) ($txn->cost_price ?? 0),
            'unit_id' => $product?->unit_id,
            'uom' => $txn->uom ?: $this->packageNameFromUom($uom),
            'packaging_label' => $this->formatPackagingLabel($uom),
            'package_name' => $this->packageNameFromUom($uom),
            'measure_unit' => $this->measureUnitFromUom($uom),
            'conversion_factor' => (float) ($uom?->conversion_factor ?? 1),
            'vat_rate' => (float) ($product?->vat?->vat_percentage ?? 0),
            'receive_status' => $this->lineReceiveStatus($ordered, $received, $returned),
            'received_qty_by_location' => [
                'shop' => $defaultReceiveLocation === 'shop' ? $received : 0,
                'store' => $defaultReceiveLocation === 'store' ? $received : 0,
            ],
            'received_location_options' => [$defaultReceiveLocation],
            'received_stock_location' => $received > 0 ? $defaultReceiveLocation : null,
            'default_receive_location' => $defaultReceiveLocation,
        ];
    }

    protected function lineReceiveStatus(float $ordered, float $received, float $returned): string
    {
        if ($ordered > 0 && $returned + 0.0001 >= $ordered) {
            return 'fully_returned';
        }
        if ($received + 0.0001 >= $ordered && $ordered > 0) {
            return 'complete';
        }
        if ($received > 0) {
            return 'partial';
        }

        return 'open';
    }

    protected function returnedQtyByProduct(int $lpoNo, int $supplierId, Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        if (Schema::hasTable('supplier_return_documents')) {
            return app(\App\Services\Purchasing\SupplierReturnDocumentService::class)->returnedQtyByProductForLpo($lpoNo);
        }

        $productCodes = $lines->pluck('product_code')->filter()->unique()->values();

        $query = SupplierReturn::query()->where('supplier_id', $supplierId);

        $query->where(function ($inner) use ($lpoNo, $productCodes) {
            $inner->where(function ($q) use ($lpoNo) {
                $q->where('reference_type', 'lpo')->where('reference_id', $lpoNo);
            })->orWhere(function ($q) use ($lpoNo) {
                $q->where('reference_type', 'lpo_no')->where('reference_id', $lpoNo);
            })->orWhereIn('product_code', $productCodes);
        });

        return $query
            ->select('product_code', DB::raw('SUM(quantity) as qty'))
            ->groupBy('product_code')
            ->pluck('qty', 'product_code')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }

    protected function supplierInvoices(int $lpoNo): array
    {
        return LpoSupplierInvoice::query()
            ->where('lpo_no', $lpoNo)
            ->orderByDesc('id')
            ->get()
            ->map(fn (LpoSupplierInvoice $inv) => [
                'id' => (int) $inv->id,
                'lpo_no' => (int) $inv->lpo_no,
                'supplier_id' => (int) $inv->supplier_id,
                'supplier_invoice_number' => $inv->supplier_invoice_number,
                'number' => $inv->supplier_invoice_number,
                'invoice_date' => $inv->invoice_date,
                'invoice_amount' => (float) ($inv->invoice_amount ?? 0),
                'has_document' => false,
                'received_at' => $inv->created_at,
            ])
            ->values()
            ->all();
    }

    protected function supplierReturns(int $lpoNo, int $supplierId, Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        if (Schema::hasTable('supplier_return_documents')) {
            return SupplierReturnDocument::query()
                ->with('lines')
                ->where('lpo_no', $lpoNo)
                ->where('supplier_id', $supplierId)
                ->orderByDesc('id')
                ->get()
                ->map(fn (SupplierReturnDocument $doc) => [
                    'id' => (int) $doc->id,
                    'status' => $doc->status === 'pending_approval' ? 'pending_approval' : $doc->status,
                    'status_label' => match ($doc->status) {
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        default => 'Pending approval',
                    },
                    'return_reason' => $doc->return_reason,
                    'notes' => $doc->notes,
                    'created_at' => $doc->created_at?->toDateTimeString(),
                    'lines' => $doc->lines->map(fn ($line) => [
                        'product_code' => $line->product_code,
                        'quantity' => (float) $line->quantity,
                    ])->values()->all(),
                ])
                ->values()
                ->all();
        }

        $productCodes = $lines->pluck('product_code')->filter()->unique();

        $rows = SupplierReturn::query()
            ->where('supplier_id', $supplierId)
            ->where(function ($q) use ($lpoNo, $productCodes) {
                $q->where(function ($inner) use ($lpoNo) {
                    $inner->where('reference_type', 'lpo')->where('reference_id', $lpoNo);
                })->orWhere(function ($inner) use ($lpoNo) {
                    $inner->where('reference_type', 'lpo_no')->where('reference_id', $lpoNo);
                })->orWhereIn('product_code', $productCodes);
            })
            ->orderByDesc('created_at')
            ->get();

        return $rows
            ->groupBy(fn (SupplierReturn $row) => ($row->created_at?->format('Y-m-d H:i') ?? 'unknown') . ':' . $row->returned_by)
            ->map(function (Collection $group) {
                /** @var SupplierReturn $first */
                $first = $group->first();

                return [
                    'id' => (int) $first->id,
                    'status' => 'approved',
                    'status_label' => 'Approved',
                    'return_reason' => $first->reason,
                    'notes' => $first->reason,
                    'created_at' => $first->created_at?->toDateTimeString(),
                    'lines' => $group->map(fn (SupplierReturn $row) => [
                        'product_code' => $row->product_code,
                        'quantity' => (float) $row->quantity,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    protected function paymentsTotal(int $lpoNo): float
    {
        if (! Schema::hasTable('supplier_payments')) {
            return 0.0;
        }

        return (float) DB::table('supplier_payments')
            ->where('lpo_no', $lpoNo)
            ->sum('amount_paid');
    }

    protected function receivedPayableTotal(Collection $lineRows): float
    {
        return $lineRows->sum(function (array $line) {
            $received = (float) ($line['received_qty'] ?? 0);
            $cost = (float) ($line['cost_price'] ?? 0);

            return $received * $cost;
        });
    }

    protected function returnCreditTotal(Collection $lineRows): float
    {
        return $lineRows->sum(function (array $line) {
            $returned = (float) ($line['returned_qty'] ?? 0);
            $cost = (float) ($line['cost_price'] ?? 0);

            return $returned * $cost;
        });
    }

    protected function paymentStatus(float $paid, float $payable): string
    {
        if ($payable <= 0) {
            return 'unpaid';
        }
        if ($paid + 0.0001 >= $payable) {
            return 'paid';
        }
        if ($paid > 0) {
            return 'partial';
        }

        return 'unpaid';
    }

    protected function packageNameFromUom(?Uom $uom): string
    {
        if (! $uom) {
            return 'package';
        }

        return trim($uom->full_name ?: $uom->uom_type ?: 'package');
    }

    protected function measureUnitFromUom(?Uom $uom): string
    {
        if (! $uom) {
            return 'units';
        }

        $type = strtolower(trim((string) $uom->uom_type));

        return match ($type) {
            'piece', 'pcs' => 'pieces',
            default => $type ?: 'units',
        };
    }

    protected function formatPackagingLabel(?Uom $uom): ?string
    {
        if (! $uom) {
            return null;
        }

        $factor = (float) ($uom->conversion_factor ?? 1);
        $package = $this->packageNameFromUom($uom);
        $measure = $this->measureUnitFromUom($uom);

        if ($factor > 1) {
            return sprintf('%g %s per %s', $factor, $measure, $package);
        }

        return $package;
    }
}
