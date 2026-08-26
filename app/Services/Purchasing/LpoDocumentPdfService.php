<?php

namespace App\Services\Purchasing;

use App\Services\LpoModuleService;
use Dompdf\Dompdf;
use Dompdf\Options;

class LpoDocumentPdfService
{
    public function __construct(
        protected LpoModuleService $lpoModule,
    ) {}

    /**
     * @return array{binary: string, filename: string}
     */
    public function buildForLpo(int $lpoNo, int $organizationId, $viewer = null): array
    {
        $summary = $this->lpoModule->summary($lpoNo, $organizationId, $viewer);
        $lpo = is_array($summary['lpo'] ?? null) ? $summary['lpo'] : [];
        $lines = is_array($summary['lines'] ?? null) ? $summary['lines'] : [];

        $html = $this->buildHtml($lpo, $lines);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $po = (string) ($lpo['po_number'] ?? $lpo['lpo_no'] ?? $lpoNo);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $po) ?: ('LPO-'.$lpoNo);

        return [
            'binary' => $dompdf->output(),
            'filename' => $safe.'.pdf',
        ];
    }

    /**
     * @param  array<string, mixed>  $lpo
     * @param  list<array<string, mixed>>  $lines
     */
    protected function buildHtml(array $lpo, array $lines): string
    {
        $po = e((string) ($lpo['po_number'] ?? $lpo['lpo_no'] ?? ''));
        $supplier = e((string) ($lpo['supplier_name'] ?? 'Supplier'));
        $status = e((string) ($lpo['status_name'] ?? ''));
        $due = e((string) ($lpo['due_date'] ?? '—'));
        $ref = e((string) ($lpo['reference_number'] ?? '—'));
        $delivery = e((string) ($lpo['delivery_address'] ?? '—'));
        $terms = e((string) ($lpo['terms'] ?? ''));
        $instructions = e((string) ($lpo['instructions'] ?? ''));
        $subtotal = number_format((float) ($lpo['subtotal'] ?? 0), 2);
        $vat = number_format((float) ($lpo['vat_amount'] ?? 0), 2);
        $total = number_format((float) ($lpo['net_amount'] ?? $lpo['total_amount'] ?? 0), 2);

        $rows = '';
        foreach ($lines as $i => $line) {
            $name = e((string) ($line['product_name'] ?? $line['product_code'] ?? ''));
            $qty = number_format((float) ($line['ordered_qty'] ?? 0), 2);
            $uom = e((string) ($line['uom'] ?? ''));
            $cost = number_format((float) ($line['cost_price'] ?? 0), 2);
            $lineTotal = number_format((float) ($line['line_total'] ?? 0), 2);
            $rows .= '<tr>'
                .'<td>'.($i + 1).'</td>'
                .'<td>'.$name.'</td>'
                .'<td style="text-align:right;">'.$qty.'</td>'
                .'<td>'.$uom.'</td>'
                .'<td style="text-align:right;">'.$cost.'</td>'
                .'<td style="text-align:right;">'.$lineTotal.'</td>'
                .'</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">No line items</td></tr>';
        }

        $notes = '';
        if ($terms !== '') {
            $notes .= '<p><strong>Terms</strong><br>'.nl2br($terms).'</p>';
        }
        if ($instructions !== '') {
            $notes .= '<p><strong>Instructions</strong><br>'.nl2br($instructions).'</p>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#0f172a;margin:18px;}
            h1{font-size:18px;margin:0 0 4px;}
            .muted{color:#64748b;font-size:10px;}
            table.items{width:100%;border-collapse:collapse;margin-top:14px;}
            table.items th,table.items td{padding:5px 4px;border-bottom:1px solid #e2e8f0;text-align:left;}
            table.items th{font-size:10px;text-transform:uppercase;color:#475569;}
            .totals{margin-top:12px;width:220px;margin-left:auto;}
            .totals td{padding:2px 0;}
            .totals .total{font-weight:bold;border-top:2px solid #0f172a;padding-top:6px;}
            .meta td{padding:2px 12px 2px 0;vertical-align:top;}
        </style></head><body>
            <h1>Local Purchase Order '.$po.'</h1>
            <p class="muted">Status: '.$status.'</p>
            <table class="meta">
                <tr><td><strong>Supplier</strong><br>'.$supplier.'</td>
                    <td><strong>Due date</strong><br>'.$due.'</td>
                    <td><strong>Reference</strong><br>'.$ref.'</td></tr>
                <tr><td colspan="3"><strong>Delivery</strong><br>'.$delivery.'</td></tr>
            </table>
            <table class="items">
                <thead><tr>
                    <th>#</th><th>Product</th><th style="text-align:right;">Qty</th>
                    <th>UoM</th><th style="text-align:right;">Cost</th><th style="text-align:right;">Amount</th>
                </tr></thead>
                <tbody>'.$rows.'</tbody>
            </table>
            <table class="totals">
                <tr><td>Subtotal</td><td style="text-align:right;">KES '.$subtotal.'</td></tr>
                <tr><td>VAT</td><td style="text-align:right;">KES '.$vat.'</td></tr>
                <tr class="total"><td>Total</td><td style="text-align:right;">KES '.$total.'</td></tr>
            </table>
            '.$notes.'
        </body></html>';
    }
}
