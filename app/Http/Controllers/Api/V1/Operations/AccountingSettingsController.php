<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Accounting\StandardChartOfAccounts;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;

class AccountingSettingsController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    public function show(Request $request)
    {
        $gate = $this->erp->gateForUser($request->user());

        return response()->json([
            'accounting' => $gate->moduleSettings('accounting'),
            'chart_seeded' => app(StandardChartOfAccounts::class)->isSeeded((int) $request->user()->organization_id),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $org = Organization::findOrFail($user->organization_id);
        $gate = $this->erp->gateForUser($user);

        $data = $request->validate([
            'auto_post_sales' => 'sometimes|boolean',
            'auto_post_expenses' => 'sometimes|boolean',
            'auto_post_purchases' => 'sometimes|boolean',
            'auto_post_payments' => 'sometimes|boolean',
            'auto_post_payroll' => 'sometimes|boolean',
            'auto_post_returns' => 'sometimes|boolean',
            'post_till_variance' => 'sometimes|boolean',
        ]);

        $current = $gate->moduleSettings('accounting');
        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['accounting'] = array_merge($current, $data);
        $org->update(['module_settings' => $moduleSettings]);

        $refreshed = $this->erp->gateForUser($user->fresh())->moduleSettings('accounting');

        return response()->json(['accounting' => $refreshed]);
    }

    public function seedChart(Request $request)
    {
        $org = Organization::findOrFail($request->user()->organization_id);
        $accounts = app(StandardChartOfAccounts::class)->seedForOrganization($org);

        return response()->json([
            'seeded' => count($accounts),
            'chart_seeded' => true,
        ], 201);
    }
}
