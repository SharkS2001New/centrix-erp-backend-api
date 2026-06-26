<?php

namespace App\Services\Background;

use App\Models\Organization;
use App\Services\Erp\GeneralSettingsResolver;
use Illuminate\Support\Facades\Storage;

class ReportBrandingService
{
    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function enrichMeta(array $meta, int $organizationId): array
    {
        $branding = $this->forOrganizationId($organizationId);

        if (! empty($meta['organization_name']) && $branding['organization_name'] === '') {
            $branding['organization_name'] = (string) $meta['organization_name'];
            $branding['watermark_text'] = $branding['organization_name'];
        }

        $meta['branding'] = $branding;

        if ($branding['show_header'] && $branding['organization_name'] !== '') {
            $meta['organization_name'] = $branding['organization_name'];
        }

        return $meta;
    }

    /** @return array<string, mixed> */
    public function forOrganizationId(int $organizationId): array
    {
        $organization = Organization::query()->find($organizationId);
        if ($organization === null) {
            return $this->emptyBranding();
        }

        return $this->forOrganization($organization);
    }

    /** @return array<string, mixed> */
    public function forOrganization(Organization $organization): array
    {
        $general = GeneralSettingsResolver::forOrganization($organization);
        $organizationName = trim((string) ($organization->org_name ?? ''));
        $logoDataUri = $this->logoDataUri($organization);
        $hasLogo = $logoDataUri !== null;
        $preference = (string) ($general['document_header_display'] ?? 'auto');
        $showHeader = (bool) ($general['show_organization_on_documents'] ?? true);

        return [
            'show_header' => $showHeader,
            'display' => $this->resolveDisplay($preference, $hasLogo),
            'organization_name' => $organizationName,
            'logo_data_uri' => $logoDataUri,
            'watermark_text' => $organizationName !== '' ? $organizationName : 'Centrix ERP',
            'document_footer_text' => trim((string) ($general['document_footer_text'] ?? '')),
        ];
    }

    public function resolveDisplay(string $preference, bool $hasLogo): string
    {
        return match ($preference) {
            'logo' => $hasLogo ? 'logo' : 'name',
            'name' => 'name',
            'logo_and_name' => $hasLogo ? 'logo_and_name' : 'name',
            default => $hasLogo ? 'logo' : 'name',
        };
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    public function buildOrgHeaderHtml(array $branding): string
    {
        if (! ($branding['show_header'] ?? false)) {
            return '';
        }

        $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $display = (string) ($branding['display'] ?? 'name');
        $name = trim((string) ($branding['organization_name'] ?? ''));
        $logo = is_string($branding['logo_data_uri'] ?? null) ? $branding['logo_data_uri'] : null;

        $parts = [];
        if (($display === 'logo' || $display === 'logo_and_name') && $logo !== null) {
            $parts[] = '<img class="org-logo" src="'.$escape($logo).'" alt="'.$escape($name).'">';
        }
        if (($display === 'name' || $display === 'logo_and_name') && $name !== '') {
            $parts[] = '<div class="org-name">'.$escape($name).'</div>';
        }

        if ($parts === []) {
            return '';
        }

        return '<div class="org-header">'.implode('', $parts).'</div>';
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    public function buildWatermarkHtml(array $branding): string
    {
        $text = trim((string) ($branding['watermark_text'] ?? ''));
        if ($text === '') {
            return '';
        }

        $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logo = is_string($branding['logo_data_uri'] ?? null) ? $branding['logo_data_uri'] : null;

        $html = '<div class="watermark-text">'.$escape($text).'</div>';
        if ($logo !== null) {
            $html .= '<img class="watermark-logo" src="'.$escape($logo).'" alt="">';
        }

        return '<div class="watermark">'.$html.'</div>';
    }

    /** @return array<string, string> */
    public function documentStyles(): array
    {
        return [
            'base' => <<<'CSS'
body { font-family: DejaVu Sans, sans-serif; padding: 24px; color: #111; font-size: 11px; position: relative; }
.org-header { text-align: center; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
.org-logo { display: block; margin: 0 auto 8px; max-height: 64px; max-width: 260px; object-fit: contain; }
.org-name { font-size: 18px; font-weight: 700; margin: 0; line-height: 1.25; color: #0f172a; }
.meta { margin-bottom: 20px; text-align: center; }
.meta h1 { font-size: 16px; margin: 0 0 4px; font-weight: 600; }
.meta p { margin: 2px 0; font-size: 12px; color: #475569; }
.doc-footer { margin-top: 18px; text-align: center; font-size: 10px; color: #64748b; }
table { width: 100%; border-collapse: collapse; position: relative; z-index: 1; }
th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
th { background: #f8fafc; }
td.num, th.num { text-align: right; }
tfoot td { font-weight: 600; background: #f8fafc; }
CSS,
            'watermark' => <<<'CSS'
.watermark { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
.watermark-text {
  position: absolute;
  top: 48%;
  left: 50%;
  transform: translate(-50%, -50%) rotate(-32deg);
  font-size: 64px;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: rgba(15, 23, 42, 0.06);
  white-space: nowrap;
}
.watermark-logo {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  max-width: 70%;
  max-height: 70%;
  opacity: 0.05;
  object-fit: contain;
}
CSS,
        ];
    }

    protected function logoDataUri(Organization $organization): ?string
    {
        if (! Organization::logoIsStoredFile($organization->logo)) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($organization->logo)) {
            return null;
        }

        $bytes = $disk->get($organization->logo);
        if ($bytes === '' || $bytes === false) {
            return null;
        }

        $mime = $disk->mimeType($organization->logo) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /** @return array<string, mixed> */
    protected function emptyBranding(): array
    {
        return [
            'show_header' => false,
            'display' => 'name',
            'organization_name' => '',
            'logo_data_uri' => null,
            'watermark_text' => 'Centrix ERP',
            'document_footer_text' => '',
        ];
    }
}
