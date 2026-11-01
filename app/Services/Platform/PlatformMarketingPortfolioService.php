<?php

namespace App\Services\Platform;

use App\Models\PlatformMarketingPortfolio;
use App\Models\User;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
use App\Support\UploadedImageProcessor;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
            'templates' => [
                ['id' => 'modern', 'label' => 'Modern', 'description' => 'Clean cards, generous spacing, and a confident blue-green palette.'],
                ['id' => 'editorial', 'label' => 'Editorial', 'description' => 'Refined typography and a calm, minimal document layout.'],
                ['id' => 'bold', 'label' => 'Bold', 'description' => 'High-contrast sections for an energetic sales presentation.'],
            ],
            'fonts' => $cfg['fonts'] ?? [],
            'section_suggestions' => $this->sectionSuggestions($cfg),
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
        $prospect = trim((string) ($data['prospect_name'] ?? ''));
        $business = trim((string) ($data['business_name'] ?? ''));
        if ($prospect === '') {
            $prospect = $business !== '' ? $business : 'Prospect';
        }
        $painPoints = trim((string) ($data['pain_points'] ?? ''));
        $instruction = trim((string) ($data['instruction'] ?? ''));
        if ($painPoints === '' && $instruction !== '') {
            $painPoints = $instruction;
        }

        $portfolio = PlatformMarketingPortfolio::query()->create([
            'title' => $data['title'] ?? $this->defaultTitle([
                'business_name' => $business,
                'prospect_name' => $prospect,
            ]),
            'status' => 'draft',
            'industry' => $industry,
            'vertical' => $vertical,
            'template_key' => $data['template_key'] ?? 'modern',
            'font_family' => $data['font_family'] ?? 'sans',
            'prospect_name' => $prospect,
            'prospect_contact' => $data['prospect_contact'] ?? null,
            'prospect_email' => $data['prospect_email'] ?? null,
            'prospect_phone' => $data['prospect_phone'] ?? null,
            'business_name' => $business !== '' ? $business : null,
            'location' => $data['location'] ?? null,
            'business_size' => $data['business_size'] ?? null,
            'branch_count' => $data['branch_count'] ?? null,
            'till_count' => $data['till_count'] ?? null,
            'pain_points' => $painPoints !== '' ? $painPoints : null,
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
            'sections' => $this->fallbackSections($portfolio, $instruction !== '' ? $instruction : null),
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
            'branch_count', 'till_count', 'pain_points', 'focus_modules', 'tone', 'template_key', 'font_family',
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

    /**
     * Store an uploaded portfolio photo after resizing and JPEG compression.
     *
     * @return array{path: string, url: string, size: int}
     */
    public function storeImage(PlatformMarketingPortfolio $portfolio, UploadedFile $file): array
    {
        $processor = UploadedImageProcessor::forPhoto();
        if (! $processor->isProcessableImage($file)) {
            throw new \RuntimeException('Upload a JPEG, PNG, or WebP image.');
        }
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('Image compression is unavailable on this server. Contact your platform administrator.');
        }

        $stored = $processor->storePublicImage($file, 'marketing-portfolios/'.$portfolio->id);

        return [
            'path' => $stored['path'],
            'url' => Storage::disk('public')->url($stored['path']),
            'size' => $stored['size'],
        ];
    }

    public function generate(PlatformMarketingPortfolio $portfolio, ?string $instruction = null): PlatformMarketingPortfolio
    {
        $instruction = $instruction !== null ? trim($instruction) : null;
        if ($instruction === '') {
            $instruction = null;
        }

        // Thin briefs: fold the instruction into pain_points so fallback + AI both see it.
        if ($instruction && ! trim((string) $portfolio->pain_points)) {
            $portfolio->forceFill(['pain_points' => $instruction])->save();
        }

        $runtime = AiSettingsResolver::resolveRuntimeForPlatformTraining();
        if (! $runtime) {
            // Still produce a usable general portfolio from vertical templates + instruction.
            $portfolio->forceFill([
                'sections' => $this->preserveUploadedSectionImages(
                    $this->fallbackSections($portfolio, $instruction),
                    $portfolio->sections ?? [],
                ),
                'ai_brief' => Str::limit($instruction ?: 'Template portfolio (no AI key)', 2000),
                'status' => $portfolio->status === 'shared' ? 'shared' : 'ready',
                'hero_image_key' => $portfolio->hero_image_key
                    ?: config("marketing_portfolio.verticals.{$portfolio->vertical}.hero_image_key", 'hero_retail'),
            ])->save();

            return $portfolio->fresh();
        }

        $prompt = $this->buildGeneratePrompt($portfolio, $instruction);
        $raw = $this->callModel($runtime, $prompt);
        $parsed = $this->parseSectionsJson($raw);
        if ($parsed === []) {
            $parsed = $this->fallbackSections($portfolio, $instruction);
        }
        $parsed = $this->preserveUploadedSectionImages($parsed, $portfolio->sections ?? []);

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
        $existingSection = collect($sections)->firstWhere('key', $sectionKey);
        if (is_array($existingSection)) {
            $parsed['image_path'] = $existingSection['image_path'] ?? null;
            $parsed['image_url'] = $existingSection['image_url'] ?? null;
        }
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

    /**
     * @param  list<array<string, mixed>>  $generated
     * @param  list<array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    protected function preserveUploadedSectionImages(array $generated, array $existing): array
    {
        $byKey = collect($existing)->keyBy('key');

        return array_map(static function (array $section) use ($byKey): array {
            $previous = $byKey->get($section['key'] ?? '');
            if (is_array($previous) && ! empty($previous['image_path'])) {
                $section['image_path'] = $previous['image_path'];
                $section['image_url'] = $previous['image_url'] ?? Storage::disk('public')->url($previous['image_path']);
            }

            return $section;
        }, $generated);
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array<string, array<string, list<string>>>
     */
    protected function sectionSuggestions(array $cfg): array
    {
        $modules = $cfg['module_catalog'] ?? [];
        $labels = $cfg['section_labels'] ?? [];
        $suggestions = [];
        foreach (($cfg['verticals'] ?? []) as $verticalId => $vertical) {
            $moduleNames = collect($vertical['default_modules'] ?? [])
                ->map(fn ($module) => $modules[$module] ?? null)
                ->filter()
                ->values()
                ->all();
            $valueBullets = array_values($vertical['value_bullets'] ?? []);
            $painHints = array_values($vertical['pain_hints'] ?? []);
            $suggestions[$verticalId] = [
                'cover' => [
                    'Name the business and industry, then promise one practical improvement.',
                    'Keep the title short enough to read at a glance.',
                ],
                'understanding' => $painHints !== []
                    ? array_slice($painHints, 0, 4)
                    : ['Describe the current workflow and the business impact.'],
                'fit' => $moduleNames !== []
                    ? ['Connect the prospect’s needs to these available Centrix areas: '.implode(', ', $moduleNames).'.']
                    : ['Connect the prospect’s needs only to verified Centrix capabilities.'],
                'day_in_life' => [
                    'Describe a realistic daily workflow from opening through close of business.',
                    'Show how the selected areas '.($moduleNames !== [] ? implode(', ', $moduleNames) : 'work together').' support the team.',
                ],
                'outcomes' => $valueBullets !== []
                    ? array_slice($valueBullets, 0, 4)
                    : ['Use measurable operational outcomes and avoid guarantees.'],
                'included' => $moduleNames !== []
                    ? ['List relevant configured capabilities: '.implode(', ', $moduleNames).'.', 'Separate included services from optional onboarding or integrations.']
                    : ['List only the selected, verified Centrix capabilities.'],
                'next_steps' => [
                    'Offer a short discovery/demo, confirm scope, then prepare a tailored quote.',
                    'Invite the prospect to confirm their priorities and decision timeline.',
                ],
            ];
        }

        foreach ($suggestions as &$verticalSuggestions) {
            foreach ($verticalSuggestions as $key => &$items) {
                $items = array_values(array_filter($items, static fn ($item) => is_string($item) && trim($item) !== ''));
                if (! isset($labels[$key])) {
                    unset($verticalSuggestions[$key]);
                }
            }
            unset($items);
        }
        unset($verticalSuggestions);

        return $suggestions;
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
    protected function fallbackSections(PlatformMarketingPortfolio $portfolio, ?string $instruction = null): array
    {
        $vCfg = config("marketing_portfolio.verticals.{$portfolio->vertical}", []);
        $labels = config('marketing_portfolio.section_labels', []);
        $modules = config('marketing_portfolio.module_catalog', []);
        $biz = $portfolio->business_name ?: $portfolio->prospect_name ?: 'your business';
        $focus = collect($portfolio->focus_modules ?? [])
            ->map(fn ($k) => $modules[$k] ?? $k)
            ->filter()
            ->values()
            ->all();
        $values = $vCfg['value_bullets'] ?? [];
        $instruction = $instruction !== null ? trim($instruction) : '';
        $pain = trim((string) ($portfolio->pain_points ?: ''));
        $briefLine = $instruction !== '' ? $instruction : ($pain !== '' ? $pain : null);
        $sections = $this->skeletonSections($portfolio->vertical);

        foreach ($sections as &$section) {
            $key = $section['key'];
            $section['title'] = $labels[$key] ?? $key;
            $section['body'] = match ($key) {
                'cover' => "A tailored Centrix overview for {$biz}"
                    .($portfolio->location ? ' in '.$portfolio->location : '')
                    .'. Built for '.($vCfg['label'] ?? 'your industry').' operations in Kenya (Kenyan Shillings).'
                    .($briefLine ? "\n\nFocus: {$briefLine}" : ''),
                'understanding' => $briefLine
                    ?: match ($portfolio->vertical) {
                        'spa_salon' => 'You need an easier way to coordinate appointments, walk-ins, service delivery, staff, and retail sales.',
                        'driving_school' => 'You need a clear view of student enrolment, lesson progress, instructor schedules, and fee balances.',
                        default => 'You need clearer stock, sales, and cash visibility without juggling spreadsheets.',
                    },
                'fit' => 'Centrix connects the tools your team already needs — '
                    .($focus !== [] ? implode(', ', $focus) : 'POS, stock, purchasing, and reports')
                    .' — in one Kenya-ready ERP.'
                    .($briefLine ? " This portfolio emphasises: {$briefLine}." : ''),
                'day_in_life' => match ($portfolio->vertical) {
                    'spa_salon' => 'Start with the appointment book and staff schedule, manage walk-ins and services during the day, then review service and retail sales at close.',
                    'driving_school' => 'Register and enrol students, schedule lessons with instructors, record attendance and assessments, then review fee collections and vehicle servicing.',
                    'hotel_bar' => 'Manage guest and outlet activity through the day, track room and F&B charges, and review stock and outlet reports at close.',
                    default => 'Morning stock checks, mid-day sales on till or counter, afternoon purchasing and credit follow-ups, and evening end-of-day — all visible to managers on web or Centrix Manager.',
                },
                'outcomes' => match ($portfolio->vertical) {
                    'spa_salon' => 'Clearer appointment and staff coordination, better visibility into service and retail sales, and more informed stock replenishment.',
                    'driving_school' => 'Clearer student progress, lesson utilization, fee balances, and vehicle readiness for day-to-day school operations.',
                    default => 'Tighter stock control, cleaner end-of-day cash, faster purchasing, and live visibility for owners and managers.',
                },
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

        $thinBrief = ! trim((string) $portfolio->business_name)
            && ! trim((string) $portfolio->pain_points)
            && trim((string) $portfolio->prospect_name) === 'Prospect';

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
            'thin_brief' => $thinBrief,
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
- If the brief is thin or prospect is generic ("Prospect"), write a strong GENERAL sales portfolio for this vertical that a Centrix seller can reuse — still concrete and Kenya SME-aware.
- If extra_instruction is set, treat it as the primary creative brief (even when other fields are empty). Shape the whole portfolio around that instruction.
- Address the prospect by name when a real name is given; otherwise say "your business" / the vertical label.
- Be specific to their vertical and pain points (or the instruction).
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
        $illustrations = config('marketing_portfolio.illustrations', []);
        $template = (string) ($portfolio->template_key ?: 'modern');
        [$accent, $secondary, $paper] = match ($template) {
            'editorial' => ['#173B3F', '#A66B42', '#F6F4EF'],
            'bold' => ['#382274', '#E86A33', '#F4F1FA'],
            default => ['#185FA5', '#16A085', '#F3F7FA'],
        };
        $fontFamily = match ($portfolio->font_family ?: 'sans') {
            'serif' => 'DejaVu Serif',
            'mono' => 'DejaVu Sans Mono',
            default => 'DejaVu Sans',
        };
        $coverSection = collect($sections)->firstWhere('key', 'cover') ?? [];
        $coverImage = $this->pdfImageData($coverSection['image_path'] ?? null);
        $coverArtConfig = $illustrations[$coverSection['image_key'] ?? $portfolio->hero_image_key] ?? [];
        $coverArtColor = $e($coverArtConfig['gradient'][0] ?? $accent);
        $coverArtLabel = $e($coverArtConfig['label'] ?? 'Centrix for your business');
        $coverArtHint = $e($coverArtConfig['hint'] ?? '');
        $coverArt = $coverImage
            ? '<img class="cover-photo" src="'.$coverImage.'" />'
            : '<div class="cover-art" style="background-color:'.$coverArtColor.';"><span class="art-brand">Centrix</span>'
                .'<span class="art-label">'.$coverArtLabel.'</span>'
                .($coverArtHint !== '' ? '<span class="art-hint">'.$coverArtHint.'</span>' : '').'</div>';
        $sectionNumber = 0;
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            if (($section['key'] ?? null) === 'cover') {
                continue;
            }
            $sectionNumber++;
            $title = $e($section['title'] ?? '');
            $text = nl2br($e($section['body'] ?? ''));
            $bullets = '';
            foreach (($section['bullets'] ?? []) as $b) {
                $bullets .= '<li>'.$e($b).'</li>';
            }
            $imageKey = (string) ($section['image_key'] ?? $portfolio->hero_image_key ?? 'ill_manager');
            $ill = $illustrations[$imageKey] ?? $illustrations['ill_manager'] ?? null;
            $g0 = $e($ill['gradient'][0] ?? '#4C5BA4');
            $illLabel = $e($ill['label'] ?? $imageKey);
            $illHint = $e($ill['hint'] ?? '');
            $photo = $this->pdfImageData($section['image_path'] ?? null);
            $imageBlock = $photo
                ? '<img class="section-photo" src="'.$photo.'" />'
                : '<div class="ill" style="background-color:'.$g0.';"><span class="art-brand">Centrix</span>'
                    .'<span class="art-label">'.$illLabel.'</span>'
                    .($illHint !== '' ? '<span class="art-hint">'.$illHint.'</span>' : '').'</div>';
            $number = str_pad((string) $sectionNumber, 2, '0', STR_PAD_LEFT);
            $copy = '<div class="section-number">'.$number.'</div><h2>'.$title.'</h2><div class="body">'.$text.'</div>'
                .($bullets !== '' ? '<ul>'.$bullets.'</ul>' : '');
            $copyCell = '<td class="copy">'.$copy.'</td>';
            $artCell = '<td class="art">'.$imageBlock.'</td>';
            $cells = $sectionNumber % 2 === 0 ? $artCell.$copyCell : $copyCell.$artCell;
            $body .= '<section class="section">'
                .'<table class="section-layout"><tr>'.$cells.'</tr></table></section>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            @page{margin:28px 34px 34px;}body{font-family:"'.$fontFamily.'",sans-serif;font-size:10px;color:#334155;line-height:1.65;margin:0;}
            .cover{background:'.$paper.';border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;margin-bottom:20px;page-break-inside:avoid;}
            .cover-art,.cover-photo{height:240px;width:100%;object-fit:cover;background-color:'.$accent.';}
            .cover-art{box-sizing:border-box;color:#fff;padding:22px 24px;}
            .cover-copy{padding:20px 24px 22px;}.brand{font-size:9px;letter-spacing:0.18em;text-transform:uppercase;color:'.$accent.';font-weight:700;}
            h1{font-size:26px;line-height:1.2;margin:8px 0 7px;color:#0f172a;}.meta{color:#475569;font-size:10px;}
            .art-brand{display:block;font-size:8px;letter-spacing:0.16em;text-transform:uppercase;color:#fff;font-weight:700;}
            .art-label{display:block;font-size:16px;font-weight:700;color:#fff;margin-top:9px;}
            .art-hint{display:block;font-size:10px;color:#fff;margin-top:5px;}
            .section{margin:14px 0;padding:18px;background:'.($template === 'editorial' ? '#fff' : $paper).';border:1px solid #e2e8f0;'.($template === 'bold' ? 'border-left:4px solid '.$accent.';' : '').'border-radius:14px;page-break-inside:avoid;}
            .section-layout{width:100%;border-collapse:collapse;}.copy{width:57%;vertical-align:middle;padding:4px 17px;}
            .art{width:43%;vertical-align:middle;padding:4px 0;}
            .section-number{font-size:9px;letter-spacing:0.16em;color:'.$secondary.';font-weight:700;margin-bottom:5px;}
            .ill{height:176px;color:#fff;border-radius:12px;padding:16px;box-sizing:border-box;}
            .section-photo{width:100%;height:176px;object-fit:cover;border-radius:12px;}
            h2{font-size:17px;line-height:1.3;margin:0 0 8px;color:#0f172a;} .body{font-size:10px;line-height:1.75;color:#334155;}
            ul{margin:10px 0 0 14px;padding:0;}li{margin:4px 0;color:#334155;}
            .footer{margin-top:22px;padding-top:10px;border-top:1px solid #e2e8f0;font-size:8px;color:#64748b;text-align:center;}
        </style></head><body>
            <header class="cover">'.$coverArt.'<div class="cover-copy"><div class="brand">Centrix ERP · Business portfolio</div>
            <h1>'.$e($portfolio->title).'</h1><div class="meta">Prepared for '.$e($biz)
            .($portfolio->location ? ' · '.$e($portfolio->location) : '')
            .' · Kenyan Shillings (KES)</div></div></header>
            '.$body.'
            <div class="footer">Prepared for '.$e($biz).' · Centrix ERP · Kenya</div>
        </body></html>';
    }

    protected function pdfImageData(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($path) ?: 'image/jpeg';
        $content = Storage::disk('public')->get($path);

        return 'data:'.$mime.';base64,'.base64_encode($content);
    }

    protected function jsonLine(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
