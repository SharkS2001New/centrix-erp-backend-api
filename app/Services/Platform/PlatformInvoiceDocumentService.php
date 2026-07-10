<?php

namespace App\Services\Platform;

use App\Models\PlatformInvoice;
use Dompdf\Dompdf;
use Dompdf\Options;

class PlatformInvoiceDocumentService
{
    public function buildHtml(PlatformInvoice $invoice): string
    {
        $currency = e((string) ($invoice->currency ?: 'KES'));
        $number = e((string) ($invoice->invoice_number ?: '#'.$invoice->id));
        $seller = is_array($invoice->seller) ? $invoice->seller : [];
        $lines = collect($invoice->line_items ?? [])
            ->filter(fn ($row) => ($row['included'] ?? true) !== false)
            ->values();

        $sellerBlock = $this->partyHtml('From', [
            $seller['name'] ?? null,
            $seller['address'] ?? null,
            $seller['email'] ?? null,
            $seller['phone'] ?? null,
            ! empty($seller['tax_pin']) ? 'PIN: '.$seller['tax_pin'] : null,
        ]);

        $billToBlock = $this->partyHtml('Bill to', [
            $invoice->bill_to_name,
            $invoice->bill_to_company_code ? 'Code: '.$invoice->bill_to_company_code : null,
            $invoice->bill_to_address,
            $invoice->bill_to_email,
            $invoice->bill_to_phone,
            $invoice->bill_to_tax_pin ? 'PIN: '.$invoice->bill_to_tax_pin : null,
        ]);

        $rowHtml = '';
        if ($lines->isEmpty()) {
            $rowHtml = '<tr><td colspan="4" style="padding:8px;color:#64748b;">No line items</td></tr>';
        } else {
            foreach ($lines as $index => $row) {
                $qty = (float) ($row['quantity'] ?? 1);
                $unit = (float) ($row['unit_price'] ?? 0);
                $amount = isset($row['amount']) ? (float) $row['amount'] : $qty * $unit;
                $rowHtml .= '<tr>'
                    .'<td style="padding:6px;border-bottom:1px solid #e2e8f0;">'.($index + 1).'</td>'
                    .'<td style="padding:6px;border-bottom:1px solid #e2e8f0;">'.e((string) ($row['description'] ?? '')).'</td>'
                    .'<td style="padding:6px;border-bottom:1px solid #e2e8f0;text-align:right;">'.$this->money($qty).'</td>'
                    .'<td style="padding:6px;border-bottom:1px solid #e2e8f0;text-align:right;">'.$currency.' '.$this->money($amount).'</td>'
                    .'</tr>';
            }
        }

        $issue = $invoice->issue_date?->format('d M Y') ?? '—';
        $due = $invoice->due_date?->format('d M Y') ?? '—';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#0f172a;margin:14px;}
            h1{font-size:18px;margin:0 0 2px;}
            .muted{color:#64748b;font-size:10px;}
            .grid{width:100%;margin-top:10px;}
            .grid td{vertical-align:top;width:50%;}
            table.items{width:100%;border-collapse:collapse;margin-top:12px;}
            table.items th{text-align:left;padding:4px;border-bottom:2px solid #0f172a;font-size:10px;text-transform:uppercase;}
            .totals{margin-top:10px;width:240px;margin-left:auto;}
            .totals td{padding:2px 0;}
            .totals .total{font-weight:bold;font-size:13px;border-top:1px solid #cbd5e1;padding-top:6px;}
            .notes{margin-top:12px;white-space:pre-wrap;}
        </style></head><body>
            <h1>Invoice '.$number.'</h1>
            <p class="muted">Issue date: '.$issue.' · Due: '.$due.' · Status: '.e((string) $invoice->status).'</p>
            <table class="grid"><tr><td>'.$sellerBlock.'</td><td>'.$billToBlock.'</td></tr></table>
            <table class="items">
                <thead><tr><th>#</th><th>Description</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Amount</th></tr></thead>
                <tbody>'.$rowHtml.'</tbody>
            </table>
            <table class="totals">
                <tr><td>Subtotal</td><td style="text-align:right;">'.$currency.' '.$this->money($invoice->subtotal).'</td></tr>
                <tr><td>Tax ('.$this->money($invoice->tax_rate).'%)</td><td style="text-align:right;">'.$currency.' '.$this->money($invoice->tax_amount).'</td></tr>
                <tr class="total"><td>Total</td><td style="text-align:right;">'.$currency.' '.$this->money($invoice->total).'</td></tr>
            </table>
            '.($invoice->notes ? '<div class="notes"><strong>Notes</strong><br>'.nl2br(e($invoice->notes)).'</div>' : '').'
            '.($invoice->terms ? '<div class="notes"><strong>Terms</strong><br>'.nl2br(e($invoice->terms)).'</div>' : '').'
        </body></html>';
    }

    public function buildPdfBinary(PlatformInvoice $invoice): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildHtml($invoice));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function attachmentFilename(PlatformInvoice $invoice): string
    {
        $raw = (string) ($invoice->invoice_number ?: 'invoice-'.$invoice->id);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $raw) ?: 'invoice';

        return $safe.'.pdf';
    }

    /** @param  list<?string>  $lines */
    protected function partyHtml(string $title, array $lines): string
    {
        $parts = array_values(array_filter(array_map(
            fn ($line) => $line !== null && trim((string) $line) !== '' ? e((string) $line) : null,
            $lines,
        )));

        $html = '<p style="font-size:11px;text-transform:uppercase;color:#64748b;margin:0 0 4px;">'.e($title).'</p>';
        foreach ($parts as $part) {
            $html .= '<p style="margin:0 0 2px;">'.$part.'</p>';
        }

        return $html;
    }

    protected function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }
}
