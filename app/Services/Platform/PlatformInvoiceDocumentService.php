<?php

namespace App\Services\Platform;

use App\Models\PlatformInvoice;
use Dompdf\Dompdf;
use Dompdf\Options;

class PlatformInvoiceDocumentService
{
    /**
     * Accent / background themes aligned with frontend platform-invoice-print.js.
     *
     * @return array{accent: string, bg: string, header: string, border?: bool}
     */
    public function themeFor(string $templateId): array
    {
        $themes = [
            'modern' => ['accent' => '#2563eb', 'bg' => '#f8fafc', 'header' => 'top'],
            'classic' => ['accent' => '#1e293b', 'bg' => '#ffffff', 'header' => 'plain', 'border' => true],
            'minimal' => ['accent' => '#64748b', 'bg' => '#ffffff', 'header' => 'plain'],
            'corporate' => ['accent' => '#0f172a', 'bg' => '#ffffff', 'header' => 'solid'],
            'bold' => ['accent' => '#dc2626', 'bg' => '#ffffff', 'header' => 'solid'],
            'elegant' => ['accent' => '#78350f', 'bg' => '#fffbeb', 'header' => 'top'],
            'stripe' => ['accent' => '#635bff', 'bg' => '#ffffff', 'header' => 'stripe'],
            'compact' => ['accent' => '#334155', 'bg' => '#ffffff', 'header' => 'top'],
            'ocean' => ['accent' => '#0d9488', 'bg' => '#f0fdfa', 'header' => 'top'],
            'forest' => ['accent' => '#166534', 'bg' => '#f7fee7', 'header' => 'solid'],
            'sunset' => ['accent' => '#ea580c', 'bg' => '#fff7ed', 'header' => 'top'],
            'slate' => ['accent' => '#475569', 'bg' => '#f8fafc', 'header' => 'solid'],
            'rose' => ['accent' => '#e11d48', 'bg' => '#fff1f2', 'header' => 'top'],
            'indigo' => ['accent' => '#4338ca', 'bg' => '#eef2ff', 'header' => 'solid'],
            'gold' => ['accent' => '#b45309', 'bg' => '#fffbeb', 'header' => 'top'],
            'paper' => ['accent' => '#78716c', 'bg' => '#fafaf9', 'header' => 'plain', 'border' => true],
            'ledger' => ['accent' => '#1c1917', 'bg' => '#ffffff', 'header' => 'top'],
            'midnight' => ['accent' => '#020617', 'bg' => '#f8fafc', 'header' => 'solid'],
            'emerald' => ['accent' => '#059669', 'bg' => '#ecfdf5', 'header' => 'top'],
            'mono' => ['accent' => '#0f766e', 'bg' => '#f8fafc', 'header' => 'top'],
            'coastal' => ['accent' => '#0284c7', 'bg' => '#f0f9ff', 'header' => 'top'],
            'graphite' => ['accent' => '#374151', 'bg' => '#f9fafb', 'header' => 'solid'],
            'ivory' => ['accent' => '#78350f', 'bg' => '#fffdf7', 'header' => 'top'],
            'magenta' => ['accent' => '#c026d3', 'bg' => '#ffffff', 'header' => 'stripe'],
            'safari' => ['accent' => '#92400e', 'bg' => '#fffbeb', 'header' => 'solid'],
            'rounded' => ['accent' => '#0ea5e9', 'bg' => '#f0f9ff', 'header' => 'top'],
        ];

        return $themes[$templateId] ?? $themes['modern'];
    }

    public function buildHtml(PlatformInvoice $invoice): string
    {
        $templateId = (string) ($invoice->template_id ?: 'modern');
        $theme = $this->themeFor($templateId);
        $options = is_array($invoice->invoice_options) ? $invoice->invoice_options : [];
        $showQty = ($options['show_quantity'] ?? true) !== false;
        $showBranding = ($options['show_branding'] ?? true) !== false;
        $showPayment = ($options['show_payment_details'] ?? true) !== false;
        $paymentDetails = trim((string) ($options['payment_details'] ?? ''));

        $currency = e((string) ($invoice->currency ?: 'KES'));
        $number = e((string) ($invoice->invoice_number ?: '#'.$invoice->id));
        $seller = is_array($invoice->seller) ? $invoice->seller : [];
        $lines = collect($invoice->line_items ?? [])
            ->filter(fn ($row) => ($row['included'] ?? true) !== false)
            ->values();

        $accent = $theme['accent'];
        $bg = $theme['bg'];
        $header = $theme['header'];
        $borderCss = ! empty($theme['border']) ? 'border:1px solid #cbd5e1;' : '';

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

        $colspan = $showQty ? 4 : 3;
        $rowHtml = '';
        if ($lines->isEmpty()) {
            $rowHtml = '<tr><td colspan="'.$colspan.'" style="padding:8px;color:#64748b;">No line items</td></tr>';
        } else {
            foreach ($lines as $index => $row) {
                $qty = (float) ($row['quantity'] ?? 1);
                $unit = (float) ($row['unit_price'] ?? 0);
                $amount = isset($row['amount']) ? (float) $row['amount'] : $qty * $unit;
                $rowHtml .= '<tr>'
                    .'<td style="padding:6px;border-bottom:1px solid #e2e8f0;">'.($index + 1).'</td>'
                    .'<td style="padding:6px;border-bottom:1px solid #e2e8f0;white-space:pre-wrap;">'.nl2br(e((string) ($row['description'] ?? ''))).'</td>';
                if ($showQty) {
                    $rowHtml .= '<td style="padding:6px;border-bottom:1px solid #e2e8f0;text-align:right;">'.$this->money($qty).'</td>';
                }
                $rowHtml .= '<td style="padding:6px;border-bottom:1px solid #e2e8f0;text-align:right;">'.$currency.' '.$this->money($amount).'</td>'
                    .'</tr>';
            }
        }

        $issue = $invoice->issue_date?->format('d M Y') ?? '—';
        $due = $invoice->due_date?->format('d M Y') ?? '—';

        $headerHtml = match ($header) {
            'solid' => '<div style="background:'.$accent.';color:#fff;padding:14px 16px;margin:-14px -14px 14px;">'
                .'<div style="font-size:11px;opacity:.85;text-transform:uppercase;letter-spacing:.04em;">Invoice</div>'
                .'<div style="font-size:20px;font-weight:bold;margin-top:2px;">'.$number.'</div>'
                .($showBranding ? '<div style="font-size:10px;opacity:.9;margin-top:4px;">'.e((string) ($seller['name'] ?? 'Centrix ERP')).'</div>' : '')
                .'</div>',
            'stripe' => '<table style="width:100%;margin-bottom:12px;"><tr>'
                .'<td style="width:8px;background:'.$accent.';"></td>'
                .'<td style="padding-left:12px;">'
                .'<div style="font-size:11px;color:'.$accent.';text-transform:uppercase;font-weight:bold;">Invoice</div>'
                .'<div style="font-size:18px;font-weight:bold;color:#0f172a;">'.$number.'</div>'
                .'</td></tr></table>',
            'plain' => '<h1 style="font-size:18px;margin:0 0 2px;color:'.$accent.';">Invoice '.$number.'</h1>',
            default => '<div style="border-top:4px solid '.$accent.';padding-top:10px;margin-bottom:8px;">'
                .'<h1 style="font-size:18px;margin:0 0 2px;color:#0f172a;">Invoice '.$number.'</h1>'
                .($showBranding ? '<p style="margin:0;color:#64748b;font-size:10px;">'.e((string) ($seller['name'] ?? '')).'</p>' : '')
                .'</div>',
        };

        $qtyHeader = $showQty
            ? '<th style="text-align:right;padding:4px;border-bottom:2px solid '.$accent.';font-size:10px;text-transform:uppercase;">Qty</th>'
            : '';

        $paymentHtml = '';
        if ($showPayment && $paymentDetails !== '') {
            $paymentHtml = '<div class="notes"><strong>Payment details</strong><br>'.nl2br(e($paymentDetails)).'</div>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#0f172a;margin:14px;background:'.$bg.';'.$borderCss.'}
            .muted{color:#64748b;font-size:10px;}
            .grid{width:100%;margin-top:10px;}
            .grid td{vertical-align:top;width:50%;}
            table.items{width:100%;border-collapse:collapse;margin-top:12px;}
            table.items th{text-align:left;padding:4px;border-bottom:2px solid '.$accent.';font-size:10px;text-transform:uppercase;color:'.$accent.';}
            .totals{margin-top:10px;width:240px;margin-left:auto;}
            .totals td{padding:2px 0;}
            .totals .total{font-weight:bold;font-size:13px;border-top:2px solid '.$accent.';padding-top:6px;color:'.$accent.';}
            .notes{margin-top:12px;white-space:pre-wrap;}
        </style></head><body>
            '.$headerHtml.'
            <p class="muted">Issue date: '.$issue.' · Due: '.$due.' · Status: '.e((string) $invoice->status).' · Design: '.e($templateId).'</p>
            <table class="grid"><tr><td>'.$sellerBlock.'</td><td>'.$billToBlock.'</td></tr></table>
            <table class="items">
                <thead><tr>
                    <th>#</th>
                    <th>Description</th>
                    '.$qtyHeader.'
                    <th style="text-align:right;">Amount</th>
                </tr></thead>
                <tbody>'.$rowHtml.'</tbody>
            </table>
            <table class="totals">
                <tr><td>Subtotal</td><td style="text-align:right;">'.$currency.' '.$this->money($invoice->subtotal).'</td></tr>
                <tr><td>Tax ('.$this->money($invoice->tax_rate).'%)</td><td style="text-align:right;">'.$currency.' '.$this->money($invoice->tax_amount).'</td></tr>
                <tr class="total"><td>Total</td><td style="text-align:right;">'.$currency.' '.$this->money($invoice->total).'</td></tr>
            </table>
            '.($invoice->notes ? '<div class="notes"><strong>Notes</strong><br>'.nl2br(e($invoice->notes)).'</div>' : '').'
            '.($invoice->terms ? '<div class="notes"><strong>Terms</strong><br>'.nl2br(e($invoice->terms)).'</div>' : '').'
            '.$paymentHtml.'
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
