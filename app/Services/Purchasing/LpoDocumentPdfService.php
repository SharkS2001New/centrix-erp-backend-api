<?php

namespace App\Services\Purchasing;

use App\Models\Organization;
use App\Models\Supplier;
use App\Services\Background\ReportBrandingService;
use App\Services\LpoModuleService;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * AI / API LPO PDF — mirrors org document branding and procurement print settings
 * (same content family as the on-screen LPO print from Printouts settings).
 */
class LpoDocumentPdfService
{
    public function __construct(
        protected LpoModuleService $lpoModule,
        protected ReportBrandingService $branding,
    ) {}

    /**
     * @return array{binary: string, filename: string}
     */
    public function buildForLpo(int $lpoNo, int $organizationId, $viewer = null): array
    {
        $summary = $this->lpoModule->summary($lpoNo, $organizationId, $viewer);
        $lpo = is_array($summary['lpo'] ?? null) ? $summary['lpo'] : [];
        $lines = is_array($summary['lines'] ?? null) ? $summary['lines'] : [];

        $organization = Organization::query()->find($organizationId);
        $procurement = $organization
            ? ProcurementSettingsResolver::forOrganization($organization)
            : ProcurementSettingsResolver::forOrganizationId($organizationId);
        $branding = $organization
            ? $this->branding->forOrganization($organization)
            : $this->branding->forOrganizationId($organizationId);

        $supplier = null;
        $supplierId = (int) ($lpo['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $supplier = Supplier::query()->find($supplierId);
        }

        $html = $this->buildHtml($lpo, $lines, $branding, $procurement, $supplier, $organization);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
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
     * @param  array<string, mixed>  $branding
     * @param  array<string, mixed>  $procurement
     */
    protected function buildHtml(
        array $lpo,
        array $lines,
        array $branding,
        array $procurement,
        ?Supplier $supplier,
        ?Organization $organization,
    ): string {
        $e = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn ($value) => number_format((float) $value, 2);

        $po = (string) ($lpo['po_number'] ?? $lpo['lpo_no'] ?? '');
        $supplierName = (string) ($lpo['supplier_name'] ?? $supplier?->supplier_name ?? 'Supplier');
        $supplierAddress = trim((string) ($supplier?->address ?? ''));
        $supplierEmail = (string) ($lpo['supplier_email'] ?? $supplier?->email ?? '—');
        $supplierPhone = (string) ($lpo['supplier_phone'] ?? $supplier?->phone ?? $supplier?->alternate_phone ?? '—');
        $supplierPin = trim((string) ($supplier?->tax_pin ?? ''));
        $supplierTown = trim((string) ($supplier?->town ?? ''));

        $orderDate = $this->formatDate($lpo['order_date'] ?? $lpo['created_at'] ?? null);
        $dueDate = $this->formatDate($lpo['due_date'] ?? null);
        $delivery = trim((string) ($lpo['delivery_address'] ?? '')) ?: '—';
        $paymentTerms = trim((string) ($lpo['terms'] ?? '')) ?: '—';
        $ref = trim((string) ($lpo['reference_number'] ?? '')) ?: '—';

        $subtotal = (float) ($lpo['subtotal'] ?? max(0, (float) ($lpo['net_amount'] ?? 0) - (float) ($lpo['vat_amount'] ?? 0)));
        $vat = (float) ($lpo['vat_amount'] ?? 0);
        $total = (float) ($lpo['net_amount'] ?? $lpo['total_amount'] ?? ($subtotal + $vat));

        $validityDays = max(1, (int) ($procurement['lpo_print_validity_days'] ?? 7));
        $deliveryNotes = $this->multilineLines((string) ($procurement['lpo_print_delivery_notes'] ?? ''));
        $kebsWarning = trim((string) ($procurement['lpo_print_kebs_warning'] ?? ''));
        $vatNote = trim((string) ($procurement['lpo_print_vat_note'] ?? ''));
        $footerLines = $this->multilineLines((string) ($procurement['lpo_print_footer_lines'] ?? ''));
        if ($footerLines === []) {
            $orgName = trim((string) ($branding['organization_name'] ?? $organization?->org_name ?? ''));
            if ($orgName !== '') {
                $footerLines[] = $orgName;
            }
            $footerLines[] = 'This LPO is valid for '.$validityDays.' day'.($validityDays === 1 ? '' : 's').'.';
        }

        $checkedBy = trim((string) ($lpo['checked_by_name'] ?? $procurement['lpo_print_checked_by'] ?? ''));
        $authorisedBy = trim((string) (
            $lpo['authorised_by_name']
            ?? $lpo['approved_by_name']
            ?? $procurement['lpo_print_authorised_by']
            ?? ''
        ));
        $preparedBy = trim((string) ($lpo['created_by_name'] ?? ''));

        $orgPhones = $this->orgPhonesLine($organization, $procurement);

        $rows = '';
        foreach ($lines as $i => $line) {
            $qty = (float) ($line['ordered_qty'] ?? 0);
            $unit = (float) ($line['cost_price'] ?? 0);
            $lineNet = (float) ($line['line_total'] ?? ($qty * $unit));
            $rate = (float) ($line['vat_rate'] ?? 0);
            $lineVat = $rate > 0 ? round($lineNet * ($rate / 100), 2) : 0.0;
            $lineGross = round($lineNet + $lineVat, 2);
            $pkg = strtolower((string) ($line['packaging_label'] ?? $line['package_name'] ?? $line['uom'] ?? '—'));
            $rows .= '<tr>'
                .'<td class="c">'.($i + 1).'</td>'
                .'<td>'.$e((string) ($line['product_name'] ?? $line['product_code'] ?? '—')).'</td>'
                .'<td>'.$e($pkg).'</td>'
                .'<td class="r">'.$money($qty).'</td>'
                .'<td class="r">'.$money($unit).'</td>'
                .'<td class="r">'.$money($lineVat).'</td>'
                .'<td class="r">'.$money($lineGross).'</td>'
                .'</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7">No line items</td></tr>';
        }

        $headerHtml = $this->branding->buildOrgHeaderHtml($branding);
        $watermarkHtml = $this->branding->buildWatermarkHtml($branding);
        $styles = $this->branding->documentStyles();

        $notesHtml = '';
        if ($deliveryNotes !== []) {
            $notesHtml .= '<div class="block"><strong>Delivery notes</strong><ul>';
            foreach ($deliveryNotes as $note) {
                $notesHtml .= '<li>'.$e($note).'</li>';
            }
            $notesHtml .= '</ul></div>';
        }
        if ($kebsWarning !== '') {
            $notesHtml .= '<p class="warn">'.$e($kebsWarning).'</p>';
        }
        if ($vatNote !== '') {
            $notesHtml .= '<p class="muted">'.$e($vatNote).'</p>';
        }

        $footerHtml = '';
        foreach ($footerLines as $line) {
            $footerHtml .= '<div>'.$e($line).'</div>';
        }
        $docFooter = trim((string) ($branding['document_footer_text'] ?? ''));
        if ($docFooter !== '') {
            $footerHtml .= '<div>'.$e($docFooter).'</div>';
        }

        $sig = static function (string $label, string $name) use ($e): string {
            $value = $name !== '' ? $name : '________________';

            return '<div class="sig"><div class="sig-line"></div><div class="sig-label">'.$e($label).'</div>'
                .'<div class="sig-name">'.$e($value).'</div></div>';
        };

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            '.$styles['base'].'
            '.$styles['watermark'].'
            body{margin:16px 18px;font-size:10.5px;}
            h1.doc-title{font-size:15px;margin:8px 0 2px;text-align:center;letter-spacing:0.04em;}
            .po-no{text-align:center;font-size:12px;font-weight:700;margin:0 0 10px;}
            .grid{width:100%;border-collapse:collapse;margin-bottom:10px;}
            .grid td{border:none;padding:2px 8px 2px 0;vertical-align:top;width:50%;}
            .label{color:#64748b;font-size:9px;text-transform:uppercase;letter-spacing:0.03em;}
            table.items th,table.items td{border:1px solid #cbd5e1;padding:5px 4px;font-size:9.5px;}
            table.items th{background:#f1f5f9;font-size:9px;text-transform:uppercase;}
            .c{text-align:center;} .r{text-align:right;}
            .totals{width:240px;margin-left:auto;margin-top:8px;border-collapse:collapse;}
            .totals td{border:none;padding:2px 0;}
            .totals .grand{font-weight:700;border-top:2px solid #0f172a;padding-top:5px;}
            .block{margin-top:10px;} .block ul{margin:4px 0 0 16px;padding:0;}
            .warn{color:#9a3412;font-weight:600;margin-top:8px;}
            .muted{color:#64748b;margin-top:4px;}
            .sigs{display:table;width:100%;margin-top:22px;}
            .sig{display:table-cell;width:33%;padding-right:10px;vertical-align:top;}
            .sig-line{border-top:1px solid #94a3b8;margin-top:28px;margin-bottom:4px;}
            .sig-label{font-size:9px;color:#64748b;text-transform:uppercase;}
            .sig-name{font-size:10px;font-weight:600;}
            .phones{text-align:center;font-size:9px;color:#475569;margin-top:2px;}
            .doc-footer{margin-top:14px;text-align:center;font-size:9px;color:#64748b;line-height:1.4;}
        </style></head><body>
            '.$watermarkHtml.'
            '.$headerHtml.'
            '.($orgPhones !== '' ? '<div class="phones">'.$e($orgPhones).'</div>' : '').'
            <h1 class="doc-title">LOCAL PURCHASE ORDER</h1>
            <div class="po-no">'.$e($po).'</div>
            <table class="grid">
                <tr>
                    <td>
                        <div class="label">Supplier</div>
                        <div><strong>'.$e($supplierName).'</strong></div>
                        <div>'.($supplierAddress !== '' ? $e($supplierAddress) : '—').'</div>
                        <div>Email: '.$e($supplierEmail).'</div>
                        <div>Phone: '.$e($supplierPhone).'</div>
                        '.($supplierPin !== '' ? '<div>PIN: '.$e($supplierPin).'</div>' : '').'
                        '.($supplierTown !== '' ? '<div>Town: '.$e($supplierTown).'</div>' : '').'
                    </td>
                    <td>
                        <div class="label">Order details</div>
                        <div>Order date: '.$e($orderDate).'</div>
                        <div>Due date: '.$e($dueDate).'</div>
                        <div>Reference: '.$e($ref).'</div>
                        <div>Payment terms: '.$e($paymentTerms).'</div>
                        <div>Deliver to: '.$e($delivery).'</div>
                        <div>Valid for: '.$e((string) $validityDays).' day'.($validityDays === 1 ? '' : 's').'</div>
                    </td>
                </tr>
            </table>
            <table class="items">
                <thead><tr>
                    <th class="c" style="width:6%;">No.</th>
                    <th style="width:28%;">Item Description</th>
                    <th style="width:14%;">Specification</th>
                    <th class="r" style="width:10%;">Qty.</th>
                    <th class="r" style="width:12%;">Unit Price</th>
                    <th class="r" style="width:12%;">V.A.T</th>
                    <th class="r" style="width:14%;">Amount KSh</th>
                </tr></thead>
                <tbody>'.$rows.'</tbody>
            </table>
            <table class="totals">
                <tr><td>Subtotal</td><td class="r">'.$money($subtotal).'</td></tr>
                <tr><td>VAT</td><td class="r">'.$money($vat).'</td></tr>
                <tr class="grand"><td>Total</td><td class="r">'.$money($total).'</td></tr>
            </table>
            '.$notesHtml.'
            <div class="sigs">
                '.$sig('Prepared by', $preparedBy).'
                '.$sig('Checked by', $checkedBy).'
                '.$sig('Authorised by', $authorisedBy).'
            </div>
            <div class="doc-footer">'.$footerHtml.'</div>
        </body></html>';
    }

    protected function formatDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->format('j F Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * @return list<string>
     */
    protected function multilineLines(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        return array_values(array_filter(array_map(
            static fn ($line) => trim((string) $line),
            $lines,
        ), static fn ($line) => $line !== ''));
    }

    /**
     * @param  array<string, mixed>  $procurement
     */
    protected function orgPhonesLine(?Organization $organization, array $procurement): string
    {
        $phones = is_array($procurement['lpo_print_phones'] ?? null)
            ? $procurement['lpo_print_phones']
            : [];
        $parts = array_values(array_filter([
            trim((string) ($phones['tel1'] ?? '')),
            trim((string) ($phones['tel2'] ?? '')),
            trim((string) ($organization?->primary_tel ?? '')),
            trim((string) ($organization?->secondary_tel ?? '')),
            trim((string) ($organization?->addn_tel1 ?? '')),
            trim((string) ($organization?->addn_tel2 ?? '')),
        ]));

        return implode(' · ', array_unique($parts));
    }
}
