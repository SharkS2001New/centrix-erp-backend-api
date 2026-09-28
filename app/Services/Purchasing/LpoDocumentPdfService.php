<?php

namespace App\Services\Purchasing;

use App\Models\Organization;
use App\Models\Supplier;
use App\Services\Background\ReportBrandingService;
use App\Services\LpoModuleService;
use App\Services\Print\OrgDocumentThemeService;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * API / Manager LPO PDF — content + visual themes aligned with ERP
 * `buildLpoPrintHtml` / Printouts → LPO document template.
 */
class LpoDocumentPdfService
{
    public function __construct(
        protected LpoModuleService $lpoModule,
        protected ReportBrandingService $branding,
        protected OrgDocumentThemeService $themes,
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

        $templateId = (string) ($procurement['lpo_document_template'] ?? 'default');
        $theme = $this->themes->themeFor($templateId);
        $accent = $theme['accent'] ?? '#2563eb';

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

        // Watermark is company name only when org has show_watermark_on_documents enabled.
        $watermarkHtml = $this->branding->buildWatermarkHtml($branding);
        $wmStyles = $this->branding->documentStyles()['watermark'] ?? '';

        $titleBand = $this->themes->titleBandHtml($theme, 'LOCAL PURCHASE ORDER', $po);
        $proHeader = $this->buildProfessionalHeaderHtml($branding, $organization, $orgPhones, $e);
        $themeCss = $this->themes->themeCss($theme);

        $notesHtml = '';
        if ($deliveryNotes !== []) {
            $notesHtml .= '<div class="block"><strong class="accent">Delivery notes</strong><ul>';
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

        $metaHtml = '<ul class="meta-list">'
            .'<li><span class="k">Supplier</span> <strong>'.$e($supplierName).'</strong></li>'
            .'<li><span class="k">Address</span> '.($supplierAddress !== '' ? $e($supplierAddress) : '—').'</li>'
            .'<li><span class="k">Email</span> '.$e($supplierEmail).'</li>'
            .'<li><span class="k">Phone</span> '.$e($supplierPhone).'</li>'
            .($supplierPin !== '' ? '<li><span class="k">PIN</span> '.$e($supplierPin).'</li>' : '')
            .($supplierTown !== '' ? '<li><span class="k">Town</span> '.$e($supplierTown).'</li>' : '')
            .'<li><span class="k">Order date</span> '.$e($orderDate).'</li>'
            .'<li><span class="k">Due date</span> '.$e($dueDate).'</li>'
            .'<li><span class="k">Reference</span> '.$e($ref).'</li>'
            .'<li><span class="k">Payment terms</span> '.$e($paymentTerms).'</li>'
            .'<li><span class="k">Deliver to</span> '.$e($delivery).'</li>'
            .'<li><span class="k">Valid for</span> '.$e((string) $validityDays).' day'.($validityDays === 1 ? '' : 's').'</li>'
            .'</ul>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body{color:#0f172a;font-size:10.5px;}
            '.$themeCss.'
            '.$wmStyles.'
            h1.doc-title{font-size:15px;margin:4px 0 2px;letter-spacing:0.04em;}
            .po-no{font-size:12px;font-weight:700;margin:0 0 8px;}
            table.items{width:100%;border-collapse:collapse;margin-top:6px;}
            table.items th,table.items td{padding:5px 4px;font-size:9.5px;}
            table.items th{font-size:9px;}
            .c{text-align:center;} .r{text-align:right;}
            .totals{width:240px;margin-left:auto;margin-top:8px;border-collapse:collapse;}
            .totals td{border:none;padding:2px 0;}
            .totals .grand{font-weight:700;padding-top:5px;}
            .block{margin-top:10px;} .block ul{margin:4px 0 0 16px;padding:0;}
            .warn{color:#9a3412;font-weight:600;margin-top:8px;}
            .muted{color:#64748b;margin-top:4px;}
            .sigs{display:table;width:100%;margin-top:22px;}
            .sig{display:table-cell;width:33%;padding-right:10px;vertical-align:top;}
            .sig-line{border-top:1px solid #94a3b8;margin-top:28px;margin-bottom:4px;}
            .sig-label{font-size:9px;color:#64748b;text-transform:uppercase;}
            .sig-name{font-size:10px;font-weight:600;}
            .doc-footer{margin-top:14px;text-align:center;font-size:9px;color:#64748b;line-height:1.4;}
            .theme-id{display:none;}
        </style></head><body>
            <span class="theme-id">'.$e($templateId).'</span>
            '.$watermarkHtml.'
            '.$proHeader.'
            '.$titleBand.'
            '.$metaHtml.'
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
                <tfoot><tr>
                    <td colspan="6" class="r" style="font-weight:700;border-top:2px solid '.$accent.';padding-top:6px;">TOTAL :</td>
                    <td class="r" style="font-weight:700;border-top:2px solid '.$accent.';padding-top:6px;color:'.$accent.';">'.$money($total).'</td>
                </tr></tfoot>
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

    /**
     * @param  array<string, mixed>  $branding
     * @param  callable(string): string  $e
     */
    protected function buildProfessionalHeaderHtml(
        array $branding,
        ?Organization $organization,
        string $orgPhones,
        callable $e,
    ): string {
        if (! ($branding['show_header'] ?? false)) {
            return '';
        }

        $display = (string) ($branding['display'] ?? 'name');
        $name = trim((string) ($branding['organization_name'] ?? $organization?->org_name ?? ''));
        $logo = is_string($branding['logo_data_uri'] ?? null) ? $branding['logo_data_uri'] : null;
        $address = trim((string) ($organization?->org_address ?? ''));
        $email = trim((string) ($organization?->org_email ?? ''));
        $pin = trim((string) ($organization?->org_pin ?? ''));

        $showLogo = ($display === 'logo' || $display === 'logo_and_name') && $logo !== null;
        $showName = ($display === 'name' || $display === 'logo_and_name') && $name !== '';

        $left = '<div class="org-block">';
        if ($showName) {
            $left .= '<div class="org-name">'.$e($name).'</div>';
        }
        if ($address !== '') {
            $left .= '<div class="org-line">'.$e($address).'</div>';
        }
        if ($email !== '') {
            $left .= '<div class="org-line">'.$e($email).'</div>';
        }
        if ($orgPhones !== '') {
            $left .= '<div class="org-line">'.$e($orgPhones).'</div>';
        }
        if ($pin !== '') {
            $left .= '<div class="org-line">PIN: '.$e($pin).'</div>';
        }
        $left .= '</div>';

        $right = $showLogo
            ? '<td class="org-logo-cell"><img src="'.$e($logo).'" alt="'.$e($name).'"></td>'
            : '<td></td>';

        if (! $showName && ! $showLogo && trim(strip_tags($left)) === '') {
            return '';
        }

        return '<table class="pro-header"><tr><td>'.$left.'</td>'.$right.'</tr></table>';
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
