<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformMarketingPortfolio;
use App\Services\Platform\PlatformMarketingPortfolioService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformMarketingPortfolioController extends Controller
{
    public function __construct(
        protected PlatformMarketingPortfolioService $portfolios,
    ) {}

    public function meta()
    {
        return response()->json($this->portfolios->meta());
    }

    public function index(Request $request)
    {
        $q = PlatformMarketingPortfolio::query()
            ->with(['creator:id,full_name,username'])
            ->orderByDesc('id');

        if ($request->filled('status') && $request->status !== 'all') {
            $q->where('status', $request->status);
        }
        if ($request->filled('vertical') && $request->vertical !== 'all') {
            $q->where('vertical', $request->vertical);
        }
        if ($request->filled('q')) {
            $term = '%'.trim((string) $request->q).'%';
            $q->where(function ($inner) use ($term) {
                $inner->where('title', 'like', $term)
                    ->orWhere('prospect_name', 'like', $term)
                    ->orWhere('business_name', 'like', $term);
            });
        }

        return response()->json(['data' => $q->limit(200)->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $portfolio = $this->portfolios->create($request->user(), $data);

        $message = 'Portfolio created.';
        if ($request->boolean('generate_now') || filled($request->input('instruction'))) {
            try {
                $portfolio = $this->portfolios->generate(
                    $portfolio,
                    $data['instruction'] ?? $request->input('instruction'),
                );
                $message = 'Portfolio generated.';
            } catch (\RuntimeException $e) {
                return response()->json([
                    'data' => $portfolio->fresh(),
                    'message' => 'Portfolio created, but generate failed: '.$e->getMessage(),
                    'generate_error' => $e->getMessage(),
                ], 201);
            }
        }

        return response()->json(['data' => $portfolio, 'message' => $message], 201);
    }

    public function show(PlatformMarketingPortfolio $platform_marketing_portfolio)
    {
        return response()->json([
            'data' => $platform_marketing_portfolio->load(['creator:id,full_name,username']),
            'meta' => $this->portfolios->meta(),
        ]);
    }

    public function update(Request $request, PlatformMarketingPortfolio $platform_marketing_portfolio)
    {
        $data = $this->validated($request, false);
        $portfolio = $this->portfolios->update($platform_marketing_portfolio, $data);

        return response()->json(['data' => $portfolio, 'message' => 'Saved.']);
    }

    public function destroy(PlatformMarketingPortfolio $platform_marketing_portfolio)
    {
        $platform_marketing_portfolio->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function generate(Request $request, PlatformMarketingPortfolio $platform_marketing_portfolio)
    {
        $data = $request->validate([
            'instruction' => 'nullable|string|max:4000',
            'save_brief_first' => 'sometimes|boolean',
        ]);

        if (! empty($data['save_brief_first'])) {
            $brief = $this->validated($request, false);
            $platform_marketing_portfolio = $this->portfolios->update($platform_marketing_portfolio, $brief);
        }

        try {
            $portfolio = $this->portfolios->generate(
                $platform_marketing_portfolio,
                $data['instruction'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $portfolio,
            'message' => 'Portfolio generated.',
        ]);
    }

    public function regenerateSection(Request $request, PlatformMarketingPortfolio $platform_marketing_portfolio)
    {
        $data = $request->validate([
            'section_key' => ['required', 'string', Rule::in(config('marketing_portfolio.section_keys', []))],
            'instruction' => 'nullable|string|max:2000',
        ]);

        try {
            $portfolio = $this->portfolios->regenerateSection(
                $platform_marketing_portfolio,
                $data['section_key'],
                $data['instruction'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $portfolio,
            'message' => 'Section updated.',
        ]);
    }

    public function share(PlatformMarketingPortfolio $platform_marketing_portfolio)
    {
        $token = $platform_marketing_portfolio->ensureShareToken();

        return response()->json([
            'data' => $platform_marketing_portfolio->fresh(),
            'share_token' => $token,
            'share_path' => '/portfolio/'.$token,
            'message' => 'Share link ready.',
        ]);
    }

    public function pdf(PlatformMarketingPortfolio $platform_marketing_portfolio)
    {
        $built = $this->portfolios->buildPdf($platform_marketing_portfolio);

        return response($built['binary'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$built['filename'].'"',
        ]);
    }

    /** Public — no auth. */
    public function shared(string $token)
    {
        $portfolio = PlatformMarketingPortfolio::query()
            ->where('share_token', $token)
            ->firstOrFail();

        return response()->json([
            'data' => $portfolio->toPublicArray(),
            'meta' => [
                'illustrations' => config('marketing_portfolio.illustrations', []),
                'section_labels' => config('marketing_portfolio.section_labels', []),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $creating = true): array
    {
        $verticals = array_keys(config('marketing_portfolio.verticals', []));

        return $request->validate([
            'title' => ($creating ? 'nullable' : 'sometimes').'|string|max:200',
            'status' => 'sometimes|string|in:draft,ready,shared',
            'industry' => 'sometimes|string|max:40',
            'vertical' => ($creating ? 'required' : 'sometimes').'|string|in:'.implode(',', $verticals ?: ['small_shop']),
            'prospect_name' => ($creating ? 'nullable' : 'sometimes').'|string|max:200',
            'prospect_contact' => 'nullable|string|max:200',
            'prospect_email' => 'nullable|email|max:200',
            'prospect_phone' => 'nullable|string|max:64',
            'business_name' => 'nullable|string|max:200',
            'location' => 'nullable|string|max:200',
            'business_size' => 'nullable|string|max:120',
            'branch_count' => 'nullable|integer|min:0|max:255',
            'till_count' => 'nullable|integer|min:0|max:255',
            'pain_points' => 'nullable|string|max:5000',
            'focus_modules' => 'nullable|array',
            'focus_modules.*' => 'string|max:40',
            'tone' => 'sometimes|string|in:professional,friendly,executive',
            'hero_image_key' => 'nullable|string|max:80',
            'sections' => 'sometimes|array',
            'organization_id' => 'nullable|integer',
            'quote_id' => 'nullable|integer',
            'instruction' => 'nullable|string|max:4000',
            'generate_now' => 'sometimes|boolean',
            'save_brief_first' => 'sometimes|boolean',
        ]);
    }
}
