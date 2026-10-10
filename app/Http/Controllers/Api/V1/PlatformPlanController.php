<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use App\Services\Platform\SelfServePlanComposer;
use Illuminate\Http\Request;

class PlatformPlanController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => PlatformPlan::query()->orderBy('name')->get(),
        ]);
    }

    public function compose(Request $request, SelfServePlanComposer $composer)
    {
        $data = $request->validate([
            'description' => 'required|string|min:2|max:4000',
        ]);

        $created = $composer->createFromWords($data['description']);

        return response()->json([
            'data' => $created['plan'],
            'summary' => $created['summary'],
            'message' => 'Centrix AI created this plan.',
        ], 201);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $plan = PlatformPlan::query()->create($data);

        return response()->json(['data' => $plan, 'message' => 'Plan created.'], 201);
    }

    public function show(PlatformPlan $platform_plan)
    {
        return response()->json(['data' => $platform_plan]);
    }

    public function update(Request $request, PlatformPlan $platform_plan)
    {
        $platform_plan->update($this->validated($request, false));

        return response()->json(['data' => $platform_plan->fresh(), 'message' => 'Plan updated.']);
    }

    public function destroy(PlatformPlan $platform_plan)
    {
        $platform_plan->delete();

        return response()->json(['message' => 'Plan deleted.']);
    }

    protected function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:200',
            'code' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'interval' => 'sometimes|in:monthly,annual',
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
            'workspace_keys' => 'sometimes|array',
            'module_keys' => 'sometimes|array',
            'section_keys' => 'sometimes|array',
            'section_keys.*' => 'string|max:40',
            'feature_details' => 'sometimes|array',
            'feature_details.*.label' => 'required_with:feature_details|string|max:120',
            'feature_details.*.detail' => 'nullable|string|max:500',
            'self_serve' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'auto_invoice_template_id' => 'nullable|integer',
        ]);
    }
}
