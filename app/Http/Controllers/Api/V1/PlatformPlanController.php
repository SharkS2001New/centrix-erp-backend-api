<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use App\Services\Platform\PackageFeatureCatalog;
use App\Services\Platform\PlatformPaybillLicenceService;
use App\Services\Platform\SelfServePlanComposer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PlatformPlanController extends Controller
{
    public function index()
    {
        $query = PlatformPlan::query();
        if (Schema::hasColumn('platform_plans', 'sort_order')) {
            $query->orderBy('sort_order')->orderBy('name');
        } else {
            $query->orderBy('name');
        }

        return response()->json([
            'data' => $query->get(),
            'catalog' => app(PackageFeatureCatalog::class)->all(),
        ]);
    }

    public function reorder(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct',
        ]);
        if (! Schema::hasColumn('platform_plans', 'sort_order')) {
            return response()->json(['message' => 'Plan order is not available yet.'], 422);
        }

        $ids = array_values(array_map('intval', $data['ids']));
        $found = PlatformPlan::query()->whereIn('id', $ids)->pluck('id');
        if ($found->count() !== count($ids)) {
            return response()->json(['message' => 'One of those plans was not found.'], 422);
        }

        foreach ($ids as $index => $id) {
            PlatformPlan::query()->whereKey($id)->update(['sort_order' => $index + 1]);
        }

        return response()->json(['message' => 'Card order saved.']);
    }

    public function compose(Request $request, SelfServePlanComposer $composer)
    {
        $data = $request->validate([
            'description' => 'required|string|min:'.SelfServePlanComposer::MIN_BRIEF_CHARS.'|max:4000',
            'industry' => 'sometimes|in:commerce,hospitality,spa,driving',
            'points' => 'required|integer|min:'.SelfServePlanComposer::MIN_POINTS.'|max:'.SelfServePlanComposer::MAX_POINTS,
        ]);

        $created = $composer->createFromWords($data['description'], $data['industry'] ?? null, (int) $data['points']);

        return response()->json([
            'data' => $created['plan'],
            'summary' => $created['summary'],
            'message' => 'Centrix AI created this plan.',
        ], 201);
    }

    public function composeUpdate(Request $request, PlatformPlan $platform_plan, SelfServePlanComposer $composer)
    {
        $data = $request->validate([
            'description' => 'required|string|min:8|max:4000',
            'industry' => 'sometimes|in:commerce,hospitality,spa,driving',
            'points' => 'required|integer|min:'.SelfServePlanComposer::MIN_POINTS.'|max:'.SelfServePlanComposer::MAX_POINTS,
        ]);

        $updated = $composer->revisePlan(
            $platform_plan,
            $data['description'],
            $data['industry'] ?? null,
            (int) $data['points'],
        );

        return response()->json([
            'data' => $updated['plan'],
            'summary' => $updated['summary'],
            'message' => 'Centrix AI updated this plan.',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $plan = PlatformPlan::query()->create($data);
        PlatformPlan::placeAtEnd($plan);
        $this->keepOneRecommended($plan->fresh());

        return response()->json(['data' => $plan->fresh(), 'message' => 'Plan created.'], 201);
    }

    public function show(PlatformPlan $platform_plan)
    {
        return response()->json(['data' => $platform_plan]);
    }

    public function update(Request $request, PlatformPlan $platform_plan)
    {
        $data = $this->validated($request, false);
        $platform_plan->update($data);
        $fresh = $platform_plan->fresh();
        $this->keepOneRecommended($fresh);
        if (array_key_exists('workspace_keys', $data) || array_key_exists('feature_keys', $data)) {
            app(PlatformPaybillLicenceService::class)->syncPlanSubscribers($fresh);
        }

        return response()->json(['data' => $fresh->fresh(), 'message' => 'Plan updated.']);
    }

    public function destroy(PlatformPlan $platform_plan)
    {
        $platform_plan->delete();

        return response()->json(['message' => 'Plan deleted.']);
    }

    protected function keepOneRecommended(PlatformPlan $plan): void
    {
        if (! $plan->is_recommended || ! \Illuminate\Support\Facades\Schema::hasColumn('platform_plans', 'is_recommended')) {
            return;
        }

        PlatformPlan::query()
            ->where('id', '!=', $plan->id)
            ->where('industry', $plan->industry)
            ->where('interval', $plan->interval)
            ->update(['is_recommended' => false]);
    }

    protected function validated(Request $request, bool $creating = true): array
    {
        $data = $request->validate([
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:200',
            'code' => 'nullable|string|max:50',
            'industry' => 'sometimes|in:commerce,hospitality,spa,driving',
            'description' => 'nullable|string',
            'interval' => 'sometimes|in:monthly,annual',
            'yearly_billing' => 'nullable|in:one_time,subscription',
            'license_basis' => 'sometimes|in:org,user',
            'price' => 'sometimes|numeric|min:0',
            'first_payment_price' => 'nullable|numeric|min:0',
            'renewal_price' => 'nullable|numeric|min:0',
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'token_pack_price' => 'nullable|numeric|min:0',
            'token_pack_days' => 'nullable|integer|min:1',
            'currency' => 'sometimes|string|max:8',
            'seat_limit' => 'nullable|integer|min:1',
            'trial_days' => 'nullable|integer|min:1|max:365',
            'workspace_keys' => 'sometimes|array',
            'module_keys' => 'sometimes|array',
            'section_keys' => 'sometimes|array',
            'section_keys.*' => 'string|max:40',
            'feature_keys' => 'sometimes|array',
            'feature_details' => 'sometimes|array',
            'feature_details.*.label' => 'required_with:feature_details|string|max:120',
            'feature_details.*.detail' => 'nullable|string|max:500',
            'self_serve' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'is_recommended' => 'sometimes|boolean',
            'auto_invoice_template_id' => 'nullable|integer',
        ]);

        if ($creating && empty($data['industry'])) {
            $data['industry'] = 'commerce';
        }

        $industry = (string) ($data['industry'] ?? '');
        if ($industry !== '' && array_key_exists('workspace_keys', $data) && ! array_key_exists('feature_keys', $data)) {
            $allowed = array_flip(config('erp_industries.definitions.'.$industry.'.permission_application_ids', []));
            $data['workspace_keys'] = array_values(array_filter(
                is_array($data['workspace_keys']) ? $data['workspace_keys'] : [],
                fn ($id) => isset($allowed[strtolower(trim((string) $id))]),
            ));
        }
        if ($industry !== '' && $industry !== 'commerce' && ! array_key_exists('feature_keys', $data)) {
            $data['section_keys'] = [];
        }

        if ($industry !== '' && array_key_exists('feature_keys', $data) && Schema::hasColumn('platform_plans', 'feature_keys')) {
            $catalog = app(PackageFeatureCatalog::class);
            $workspaces = array_key_exists('workspace_keys', $data)
                ? (array) $data['workspace_keys']
                : [];
            $data['feature_keys'] = $catalog->normalize(
                $industry,
                $workspaces,
                is_array($data['feature_keys']) ? $data['feature_keys'] : [],
            );
            $data['workspace_keys'] = array_keys($data['feature_keys']);
            $sections = $catalog->backofficeSections($data['feature_keys']);
            if ($sections !== null) {
                $data['section_keys'] = $sections;
            }
        }

        $interval = strtolower((string) ($data['interval'] ?? ''));
        if ($interval === 'monthly') {
            $data['yearly_billing'] = null;
        } elseif (in_array($interval, ['annual', 'yearly'], true)) {
            $mode = strtolower((string) ($data['yearly_billing'] ?? 'one_time'));
            $data['yearly_billing'] = $mode === 'subscription' ? 'subscription' : 'one_time';
            if ($data['yearly_billing'] === 'subscription' && array_key_exists('yearly_price', $data)) {
                $data['renewal_price'] = $data['yearly_price'];
                $data['first_payment_price'] = $data['yearly_price'];
            }
        }

        return $data;
    }
}
