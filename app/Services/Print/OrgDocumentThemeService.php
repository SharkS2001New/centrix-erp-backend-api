<?php

namespace App\Services\Print;

/**
 * Org document design themes — mirrors frontend `document-print-templates.js` THEME_MAP
 * so DomPDF downloads (Manager / API) match ERP on-screen print themes.
 */
class OrgDocumentThemeService
{
    /**
     * @return array{
     *   accent: string,
     *   bg: string,
     *   header: string,
     *   border?: bool,
     *   serif?: bool,
     *   compact?: bool
     * }
     */
    public function themeFor(string $templateId): array
    {
        $id = strtolower(trim($templateId));
        $themes = [
            'default' => ['accent' => '#2563eb', 'bg' => '#f8fafc', 'header' => 'top'],
            'modern' => ['accent' => '#2563eb', 'bg' => '#f8fafc', 'header' => 'top'],
            'classic' => ['accent' => '#1e293b', 'bg' => '#ffffff', 'header' => 'plain', 'border' => true, 'serif' => true],
            'minimal' => ['accent' => '#64748b', 'bg' => '#ffffff', 'header' => 'plain'],
            'corporate' => ['accent' => '#0f172a', 'bg' => '#ffffff', 'header' => 'solid'],
            'bold' => ['accent' => '#dc2626', 'bg' => '#ffffff', 'header' => 'solid'],
            'elegant' => ['accent' => '#78350f', 'bg' => '#fffbeb', 'header' => 'top', 'serif' => true],
            'stripe' => ['accent' => '#635bff', 'bg' => '#ffffff', 'header' => 'stripe'],
            'compact' => ['accent' => '#334155', 'bg' => '#ffffff', 'header' => 'top', 'compact' => true],
            'ocean' => ['accent' => '#0d9488', 'bg' => '#f0fdfa', 'header' => 'top'],
            'forest' => ['accent' => '#166534', 'bg' => '#f7fee7', 'header' => 'solid'],
            'sunset' => ['accent' => '#ea580c', 'bg' => '#fff7ed', 'header' => 'top'],
            'slate' => ['accent' => '#475569', 'bg' => '#f8fafc', 'header' => 'solid'],
            'rose' => ['accent' => '#e11d48', 'bg' => '#fff1f2', 'header' => 'top'],
            'indigo' => ['accent' => '#4338ca', 'bg' => '#eef2ff', 'header' => 'solid'],
            'gold' => ['accent' => '#b45309', 'bg' => '#fffbeb', 'header' => 'top', 'serif' => true],
            'paper' => ['accent' => '#78716c', 'bg' => '#fafaf9', 'header' => 'plain', 'border' => true, 'serif' => true],
            'ledger' => ['accent' => '#1c1917', 'bg' => '#ffffff', 'header' => 'top'],
            'midnight' => ['accent' => '#020617', 'bg' => '#f8fafc', 'header' => 'solid'],
            'emerald' => ['accent' => '#059669', 'bg' => '#ecfdf5', 'header' => 'top'],
            'mono' => ['accent' => '#0f766e', 'bg' => '#f8fafc', 'header' => 'top'],
            'coastal' => ['accent' => '#0284c7', 'bg' => '#f0f9ff', 'header' => 'top'],
            'graphite' => ['accent' => '#374151', 'bg' => '#f9fafb', 'header' => 'solid'],
            'ivory' => ['accent' => '#78350f', 'bg' => '#fffdf7', 'header' => 'top', 'serif' => true],
            'magenta' => ['accent' => '#c026d3', 'bg' => '#ffffff', 'header' => 'stripe'],
            'safari' => ['accent' => '#92400e', 'bg' => '#fffbeb', 'header' => 'solid'],
            'rounded' => ['accent' => '#0ea5e9', 'bg' => '#f0f9ff', 'header' => 'top'],
        ];

        return $themes[$id] ?? $themes['modern'];
    }

    /**
     * Title band HTML for professional A4 documents (LPO, invoices, etc.).
     */
    public function titleBandHtml(array $theme, string $title, string $subtitle = ''): string
    {
        $e = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $accent = $theme['accent'] ?? '#2563eb';
        $header = $theme['header'] ?? 'top';
        $safeTitle = $e($title);
        $safeSub = $subtitle !== '' ? $e($subtitle) : '';

        return match ($header) {
            'solid' => '<div class="title-band solid" style="background:'.$accent.';color:#fff;">'
                .'<div class="title-kicker">Document</div>'
                .'<div class="title-main">'.$safeTitle.'</div>'
                .($safeSub !== '' ? '<div class="title-sub">'.$safeSub.'</div>' : '')
                .'</div>',
            'stripe' => '<table class="title-band stripe" style="width:100%;border-collapse:collapse;margin-bottom:10px;"><tr>'
                .'<td style="width:8px;background:'.$accent.';"></td>'
                .'<td style="padding-left:12px;">'
                .'<div class="title-kicker" style="color:'.$accent.';">Document</div>'
                .'<div class="title-main" style="color:#0f172a;">'.$safeTitle.'</div>'
                .($safeSub !== '' ? '<div class="title-sub" style="color:#64748b;">'.$safeSub.'</div>' : '')
                .'</td></tr></table>',
            'plain' => '<div class="title-band plain">'
                .'<h1 class="doc-title" style="color:'.$accent.';">'.$safeTitle.'</h1>'
                .($safeSub !== '' ? '<div class="po-no">'.$safeSub.'</div>' : '')
                .'</div>',
            default => '<div class="title-band top" style="border-top:4px solid '.$accent.';">'
                .'<h1 class="doc-title">'.$safeTitle.'</h1>'
                .($safeSub !== '' ? '<div class="po-no">'.$safeSub.'</div>' : '')
                .'</div>',
        };
    }

    /**
     * Extra CSS variables driven by the selected theme.
     */
    public function themeCss(array $theme): string
    {
        $accent = $theme['accent'] ?? '#2563eb';
        $bg = $theme['bg'] ?? '#ffffff';
        $border = ! empty($theme['border']) ? '1px solid '.$accent : 'none';
        $font = ! empty($theme['serif'])
            ? 'DejaVu Serif, Times New Roman, serif'
            : 'DejaVu Sans, Helvetica, sans-serif';
        $pad = ! empty($theme['compact']) ? '12px 14px' : '16px 18px';

        return '
            body{font-family:'.$font.';background:'.$bg.';margin:'.$pad.';'
            .($border !== 'none' ? 'border:'.$border.';' : '').'}
            .accent{color:'.$accent.';}
            table.items th{border-bottom:2px solid '.$accent.';color:'.$accent.';background:transparent;text-transform:uppercase;}
            table.items td{border-bottom:1px solid #e2e8f0;}
            .totals .grand{border-top:2px solid '.$accent.';color:'.$accent.';}
            .title-band.solid{padding:12px 14px;margin:-16px -18px 12px;}
            .title-kicker{font-size:9px;text-transform:uppercase;letter-spacing:0.04em;opacity:0.9;}
            .title-main{font-size:16px;font-weight:700;margin-top:2px;}
            .title-sub{font-size:11px;margin-top:2px;font-weight:600;}
            .title-band.top{padding-top:8px;margin-bottom:8px;}
            .title-band.plain h1{margin:0 0 2px;}
            .pro-header{width:100%;border-collapse:collapse;margin-bottom:8px;}
            .pro-header td{border:none;vertical-align:top;padding:0;}
            .org-block .org-name{font-size:14px;font-weight:700;color:#0f172a;margin:0 0 2px;}
            .org-block .org-line{font-size:9.5px;color:#475569;line-height:1.35;}
            .org-logo-cell{text-align:right;width:28%;}
            .org-logo-cell img{max-height:56px;max-width:140px;}
            .meta-list{margin:0 0 10px;padding:0;list-style:none;}
            .meta-list li{margin:0 0 3px;font-size:10px;line-height:1.4;}
            .meta-list .k{color:#64748b;font-weight:700;display:inline-block;min-width:118px;}
        ';
    }
}
