<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use Illuminate\Http\Request;

class PlatformPlanController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => PlatformPlan::query()->orderBy('name')->get(),
        ]);
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
            'currency' => 'sometimes|string|max:8',
            'seat_limit' => 'nullable|integer|min:1',
            'workspace_keys' => 'sometimes|array',
            'module_keys' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
            'auto_invoice_template_id' => 'nullable|integer',
        ]);
    }
}
