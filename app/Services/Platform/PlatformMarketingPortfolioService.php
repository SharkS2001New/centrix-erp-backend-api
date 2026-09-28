<?php

namespace App\Services\Platform;

use App\Models\PlatformMarketingPortfolio;
use App\Models\User;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
use App\Exceptions\Ai\AiProviderException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlatformMarketingPortfolioService
{
    public function __construct(
        protected AiProviderFactory $providers,
    ) {}
    /** @return array<string, mixed> */
    public function meta(): array
    {
        $cfg = config('marketing_portfolio');

        return [
            'verticals' => collect($cfg['verticals'] ?? [])->map(function ($row, $key) {
                return [
                    'id' => $key,
                    'label' => $row['label'] ?? $key,
                    'industry' => $row['industry'] ?? 'commerce',
                    'hero_image_key' => $row['hero_image_key'] ?? null,
                    'default_modules' => $row['default_modules'] ?? [],
                    'pain_hints' => $row['pain_hints'] ?? [],
                    'value_bullets' => $row['value_bullets'] ?? [],
                ];
            })->values()->all(),
            'modules' => $cfg['module_catalog'] ?? [],
            'illustrations' => $cfg['illustrations'] ?? [],
            'section_keys' => $cfg['section_keys'] ?? [],
            'section_labels' => $cfg['section_labels'] ?? [],
            'tones' => [
                ['id' => 'professional', 'label' => 'Professional'],
                ['id' => 'friendly', 'label' => 'Friendly'],
                ['id' => 'executive', 'label' => 'Executive brief'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): PlatformMarketingPortfolio
    {
        $vertical = (string) ($data['vertical'] ?? 'small_shop');
        $vCfg = config("marketing_portfolio.verticals.{$vertical}", []);
        $industry = (string) ($data['industry'] ?? ($vCfg['industry'] ?? 'commerce'));

        $portfolio = PlatformMarketingPortfolio::query()->create([
            'title' => $data['title'] ?? $this->defaultTitle($data),
            'status' => 'draft',
            'industry' => $industry,
            'vertical' => $vertical,
            'prospect_name' => $data['prospect_name'],
            'prospect_contact' => $data['prospect_contact'] ?? null,
            'prospect_email' => $data['prospect_email'] ?? null,
            'prospect_phone' => $data['prospect_phone'] ?? null,
            'business_name' => $data['business_name'] ?? null,
            'location' => $data['location'] ?? null,
            'business_size' => $data['business_size'] ?? null,
            'branch_count' => $data['branch_count'] ?? null,
            'till_count' => $data['till_count'] ?? null,
            'pain_points' => $data['pain_points'] ?? null,
            'focus_modules' => $data['focus_modules'] ?? ($vCfg['default_modules'] ?? []),
            'tone' => $data['tone'] ?? 'professional',
            'hero_image_key' => $data['hero_image_key'] ?? ($vCfg['hero_image_key'] ?? 'hero_retail'),
            'sections' => $this->skeletonSections($vertical),
            'created_by' => $user->id,
            'organization_id' => $data['organization_id'] ?? null,
            'quote_id' => $data['quote_id'] ?? null,
        ]);

        // Seed readable draft copy so preview/PDF work before AI generate.
        $portfolio->forceFill([
            'sections' => $this->fallbackSections($portfolio),
        ])->save();

        return $portfolio->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PlatformMarketingPortfolio $portfolio, array $data): PlatformMarketingPortfolio
    {
        $allowed = [
            'title', 'status', 'industry', 'vertical', 'prospect_name', 'prospect_contact',
            'prospect_email', 'prospect_phone', 'business_name', 'location', 'business_size',
            'branch_count', 'till_count', 'pain_points', 'focus_modules', 'tone',
            'hero_image_key', 'sections', 'organization_id', 'quote_id',
        ];
        $patch = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $patch[$key] = $data[$key];
            }
        }
        if (isset($patch['vertical']) && ! isset($patch['industry'])) {
            $patch['industry'] = config("marketing_portfolio.verticals.{$patch['vertical']}.industry", $portfolio->industry);
        }
        if (isset($patch['vertical']) && ! isset($patch['hero_image_key'])) {
            $patch['hero_image_key'] = config(
                "marketing_portfolio.verticals.{$patch['vertical']}.hero_image_key",
                $portfolio->hero_image_key,
            );
        }
        $portfolio->fill($patch)->save();

        return $portfolio->fresh();
    }

    public function generate(PlatformMarketingPortfolio $portfolio, ?string $instruction = null): PlatformMarketingPortfolio
    {
        $runtime = AiSettingsResolver::resolveRuntimeForPlatformTraining();
        if (! $runtime) {
            throw new \RuntimeException(
                'No Gemini or OpenAI key is saved for platform tools. Add a key under Platform → AI training → Credentials.',
            );
        }

        $prompt = $this->buildGeneratePrompt($portfolio, $instruction);
        $raw = $this->callModel($runtime, $prompt);
        $parsed = $this->parseSectionsJson($raw);
        if ($parsed === []) {
            $parsed = $this->fallbackSections($portfolio);
        }

        $portfolio->forceFill([
            'sections' => $parsed,
            'ai_brief' => Str::limit($instruction ?: 'Full portfolio generate', 2000),
            'status' => $portfolio->status === 'shared' ? 'shared' : 'ready',
            'hero_image_key' => $portfolio->hero_image_key
                ?: config("marketing_portfolio.verticals.{$portfolio->vertical}.hero_image_key", 'hero_retail'),
        ])->save();

        return $portfolio->fresh();
    }

    public function regenerateSection(
        PlatformMarketingPortfolio $portfolio,
        string $sectionKey,
        ?string $instruction = null,
    ): PlatformMarketingPortfolio {
        $runtime = AiSettingsResolver::resolveRuntimeForPlatformTraining();
        if (! $runtime) {
            throw new \RuntimeException(
                'No Gemini or OpenAI key is saved for platform tools. Add a key under Platform → AI training → Credentials.',
            );
        }

        $labels = config('marketing_portfolio.section_labels', []);
        $label = $labels[$sectionKey] ?? $sectionKey;
        $existing = collect($portfolio->sections ?? [])->firstWhere('key', $sectionKey);
        $prompt = $this->buildSectionPrompt($portfolio, $sectionKey, $label, $existing, $instruction);
        $raw = $this->callModel($runtime, $prompt);
        $parsed = $this->parseSingleSectionJson($raw, $sectionKey, $label);
        if ($parsed === null) {
            throw new \RuntimeException('AI did not return a usable section. Try again.');
        }

        $sections = is_array($portfolio->sections) ? $portfolio->sections : [];
        $found = false;
        foreach ($sections as $i => $row) {
            if (($row['key'] ?? '') === $sectionKey) {
                $sections[$i] = $parsed;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $sections[] = $parsed;
        }

        $portfolio->forceFill([
            'sections' => array_values($sections),
            'status' => $portfolio->status === 'draft' ? 'ready' : $portfolio->status,
        ])->save();

        return $portfolio->fresh();
    }

    /** @return array{binary: string, filename: string} */
    public function buildPdf(PlatformMarketingPortfolio $portfolio): array
    {
        $html = $this->buildPdfHtml($portfolio);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $portfolio->title) ?: 'Centrix-Portfolio';

        return [
            'binary' => $dompdf->output(),
            'filename' => $safe.'.pdf',
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function defaultTitle(array $data): string
    {
        $biz = trim((string) ($data['business_name'] ?? $data['prospect_name'] ?? 'Prospect'));

        return 'Centrix for '.$biz;
    }

    /** @return list<array<string, mixed>> */
    protected function skeletonSections(string $vertical): array
    {
        $keys = config('marketing_portfolio.section_keys', []);
        $labels = config('marketing_portfolio.section_labels', []);
        $vCfg = config("marketing_portfolio.verticals.{$vertical}", []);
        $hero = $vCfg['hero_image_key'] ?? 'hero_retail';
        $map = [
            'cover' => $hero,
            'understanding' => 'ill_manager',
            'fit' => 'ill_pos',
            'day_in_life' => 'ill_stock',
            'outcomes' => 'ill_finance',
            'included' => 'ill_manager',
            'next_steps' => $hero,
        ];
        if (($vCfg['industry'] ?? '') === 'hospitality') {
            $map['fit'] = str_contains($vertical, 'spa') ? 'ill_spa' : 'ill_hotel';
        }

        $out = [];
        foreach ($keys as $key) {
            $out[] = [
                'key' => $key,
                'title' => $labels[$key] ?? $key,
                'body' => '',
                'bullets' => [],
                'image_key' => $map[$key] ?? 'ill_manager',
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    protected function fallbackSections(PlatformMarketingPortfolio $portfolio): array
    {
        $vCfg = config("marketing_portfolio.verticals.{$portfolio->vertical}", []);
        $labels = config('marketing_portfolio.section_labels', []);
        $modules = config('marketing_portfolio.module_catalog', []);
        $biz = $portfolio->business_name ?: $portfolio->prospect_name;
        $focus = collect($portfolio->focus_modules ?? [])
            ->map(fn ($k) => $modules[$k] ?? $k)
            ->filter()
            ->values()
            ->all();
        $values = $vCfg['value_bullets'] ?? [];
        $sections = $this->skeletonSections($portfolio->vertical);

        foreach ($sections as &$section) {
            $key = $section['key'];
            $section['title'] = $labels[$key] ?? $key;
            $section['body'] = match ($key) {
                'cover' => "A tailored Centrix overview for {$biz}"
                    .($portfolio->location ? ' in '.$portfolio->location : '')
                    .'. Built for '.($vCfg['label'] ?? 'your industry').' operations in Kenya (Kenyan Shillings).',
                'understanding' => trim((string) ($portfolio->pain_points ?: 'You need clearer stock, sales, and cash visibility without juggling spreadsheets.')),
                'fit' => 'Centrix connects the tools your team already needs — '
                    .($focus !== [] ? implode(', ', $focus) : 'POS, stock, purchasing, and reports')
                    .' — in one Kenya-ready ERP.',
                'day_in_life' => 'Morning stock checks, mid-day sales on till or counter, afternoon purchasing and credit follow-ups, and evening end-of-day — all visible to managers on web or Centrix Manager.',
                'outcomes' => 'Tighter stock control, cleaner end-of-day cash, faster purchasing, and live visibility for owners and managers.',
                'included' => 'A practical Centrix footprint for '.($vCfg['label'] ?? 'your business')
                    .' with onboarding support from the Centrix team.',
                'next_steps' => 'Review this portfolio together, confirm the modules that matter most, then we prepare a quote and provision your company on Centrix.',
                default => '',
            };
            $section['bullets'] = match ($key) {
                'fit', 'outcomes', 'included' => array_values($values),
                default => [],
            };
        }
        unset($section);

        return $sections;
    }

    protected function buildGeneratePrompt(PlatformMarketingPortfolio $portfolio, ?string $instruction): string
    {
        $truth = config('marketing_portfolio.product_truth', '');
        $vCfg = config("marketing_portfolio.verticals.{$portfolio->vertical}", []);
        $labels = config('marketing_portfolio.section_labels', []);
        $modules = config('marketing_portfolio.module_catalog', []);
        $focus = collect($portfolio->focus_modules ?? [])
            ->map(fn ($k) => ($modules[$k] ?? $k).' ('.$k.')')
            ->implode(', ');
        $sectionList = collect($labels)->map(fn ($label, $key) => "- {$key}: {$label}")->implode("\n");

        $brief = json_encode([
            'prospect_name' => $portfolio->prospect_name,
            'business_name' => $portfolio->business_name,
            'location' => $portfolio->location,
            'vertical' => $portfolio->vertical,
            'vertical_label' => $vCfg['label'] ?? $portfolio->vertical,
            'industry' => $portfolio->industry,
            'business_size' => $portfolio->business_size,
            'branch_count' => $portfolio->branch_count,
            'till_count' => $portfolio->till_count,
            'pain_points' => $portfolio->pain_points,
            'focus_modules' => $focus,
            'tone' => $portfolio->tone,
            'extra_instruction' => $instruction,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You write professional Centrix marketing portfolios for Kenyan prospects.

PRODUCT TRUTH (do not invent features outside this):
{$truth}

VERTICAL HINTS:
Label: {$vCfg['label']}
Value bullets: {$this->jsonLine($vCfg['value_bullets'] ?? [])}

PROSPECT BRIEF:
{$brief}

Return JSON ONLY with this shape:
{
  "sections": [
    {
      "key": "cover",
      "title": "...",
      "body": "2-4 short paragraphs or sentences",
      "bullets": ["optional", "bullet"],
      "image_key": "hero_retail"
    }
  ]
}

Required section keys (in order):
{$sectionList}

Rules:
- Currency is Kenyan Shillings (say Kenyan Shillings / KES clearly).
- Address the prospect by name where natural.
- Be specific to their vertical and pain points.
- image_key must be one of: hero_retail, hero_supermarket, hero_hotel, hero_spa, hero_distribution, ill_pos, ill_stock, ill_finance, ill_hr, ill_manager, ill_hotel, ill_spa.
- No markdown fences. JSON only.
PROMPT;
    }

    protected function buildSectionPrompt(
        PlatformMarketingPortfolio $portfolio,
        string $sectionKey,
        string $label,
        mixed $existing,
        ?string $instruction,
    ): string {
        $truth = config('marketing_portfolio.product_truth', '');
        $existingJson = json_encode($existing ?: new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $brief = json_encode([
            'prospect_name' => $portfolio->prospect_name,
            'business_name' => $portfolio->business_name,
            'vertical' => $portfolio->vertical,
            'pain_points' => $portfolio->pain_points,
            'tone' => $portfolio->tone,
            'instruction' => $instruction,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Rewrite ONE Centrix portfolio section. PRODUCT TRUTH:
{$truth}

Brief:
{$brief}

Section key: {$sectionKey}
Section label: {$label}
Current section JSON:
{$existingJson}

Return JSON ONLY:
{"key":"{$sectionKey}","title":"...","body":"...","bullets":[],"image_key":"ill_manager"}
No markdown fences.
PROMPT;
    }

    /** @param  array<string, mixed>  $runtime */
    protected function callModel(array $runtime, string $userPrompt): string
    {
        $system = 'You are Centrix marketing copywriter. Return valid JSON only.';

        try {
            $turn = $this->providers->make($runtime)->chat([
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.45,
                'max_output_tokens' => max(3500, (int) config('ai.defaults.max_output_tokens', 2048)),
            ]);

            return trim((string) ($turn['text'] ?? ''));
        } catch (AiProviderException $e) {
            Log::warning('Marketing portfolio AI generate failed', ['message' => $e->getMessage()]);

            throw new \RuntimeException('AI generate failed: '.$e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            Log::warning('Marketing portfolio AI generate failed', ['message' => $e->getMessage()]);

            throw new \RuntimeException('AI generate failed: '.$e->getMessage(), 0, $e);
        }
    }

    /** @return list<array<string, mixed>> */
    protected function parseSectionsJson(string $raw): array
    {
        $json = $this->extractJson($raw);
        if (! is_array($json)) {
            return [];
        }
        $rows = $json['sections'] ?? $json;
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        $allowedKeys = config('marketing_portfolio.section_keys', []);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            if ($key === '' || (! empty($allowedKeys) && ! in_array($key, $allowedKeys, true))) {
                continue;
            }
            $out[] = $this->normalizeSection($row, $key);
        }

        // Keep configured order.
        if ($allowedKeys !== []) {
            $byKey = collect($out)->keyBy('key');
            $ordered = [];
            foreach ($allowedKeys as $key) {
                if ($byKey->has($key)) {
                    $ordered[] = $byKey->get($key);
                }
            }

            return $ordered !== [] ? $ordered : $out;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    protected function parseSingleSectionJson(string $raw, string $sectionKey, string $label): ?array
    {
        $json = $this->extractJson($raw);
        if (! is_array($json)) {
            return null;
        }
        if (isset($json['sections'][0]) && is_array($json['sections'][0])) {
            $json = $json['sections'][0];
        }
        $json['key'] = $sectionKey;
        if (empty($json['title'])) {
            $json['title'] = $label;
        }

        return $this->normalizeSection($json, $sectionKey);
    }

    /** @param  array<string, mixed>  $row */
    protected function normalizeSection(array $row, string $key): array
    {
        $bullets = $row['bullets'] ?? [];
        if (! is_array($bullets)) {
            $bullets = [];
        }
        $bullets = array_values(array_filter(array_map(
            static fn ($b) => trim((string) $b),
            $bullets,
        ), static fn ($b) => $b !== ''));

        $imageKey = (string) ($row['image_key'] ?? 'ill_manager');
        $illustrations = config('marketing_portfolio.illustrations', []);
        if (! isset($illustrations[$imageKey])) {
            $imageKey = 'ill_manager';
        }

        return [
            'key' => $key,
            'title' => trim((string) ($row['title'] ?? config("marketing_portfolio.section_labels.{$key}", $key))),
            'body' => trim((string) ($row['body'] ?? '')),
            'bullets' => $bullets,
            'image_key' => $imageKey,
        ];
    }

    protected function extractJson(string $raw): ?array
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
            $text = trim($m[1]);
        }
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable) {
            /* continue */
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        try {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function buildPdfHtml(PlatformMarketingPortfolio $portfolio): string
    {
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $biz = $portfolio->business_name ?: $portfolio->prospect_name;
        $sections = is_array($portfolio->sections) ? $portfolio->sections : [];
        $body = '';
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $title = $e($section['title'] ?? '');
            $text = nl2br($e($section['body'] ?? ''));
            $bullets = '';
            foreach (($section['bullets'] ?? []) as $b) {
                $bullets .= '<li>'.$e($b).'</li>';
            }
            $body .= '<section class="sec"><h2>'.$title.'</h2><div class="body">'.$text.'</div>'
                .($bullets !== '' ? '<ul>'.$bullets.'</ul>' : '')
                .'</section>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#0f172a;margin:28px 32px;line-height:1.45;}
            .brand{font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#4C5BA4;font-weight:700;}
            h1{font-size:22px;margin:8px 0 4px;}
            .meta{color:#64748b;margin-bottom:18px;}
            .sec{margin:16px 0;padding-top:10px;border-top:1px solid #e2e8f0;}
            h2{font-size:14px;margin:0 0 6px;color:#4C5BA4;}
            ul{margin:8px 0 0 18px;padding:0;}
            li{margin:3px 0;}
            .footer{margin-top:28px;font-size:9px;color:#94a3b8;text-align:center;}
        </style></head><body>
            <div class="brand">Centrix</div>
            <h1>'.$e($portfolio->title).'</h1>
            <div class="meta">Prepared for '.$e($biz)
            .($portfolio->location ? ' · '.$e($portfolio->location) : '')
            .' · Kenyan Shillings (KES)</div>
            '.$body.'
            <div class="footer">Centrix ERP · Kenya · centrix portfolio</div>
        </body></html>';
    }

    protected function jsonLine(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
