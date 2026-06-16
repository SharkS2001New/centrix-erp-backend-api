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
            'account_codes' => 'sometimes|array',
            'account_codes.*' => 'nullable|string|max:20',
            'payment_method_accounts' => 'sometimes|array',
            'payment_method_accounts.*' => 'nullable|string|max:20',
        ]);

        $current = $gate->moduleSettings('accounting');
        $moduleSettings = $org->module_settings ?? [];
        $merged = array_merge($current, $data);
        if (isset($data['account_codes']) && is_array($data['account_codes'])) {
            $merged['account_codes'] = array_merge(
                is_array($current['account_codes'] ?? null) ? $current['account_codes'] : [],
                array_filter($data['account_codes'], fn ($v) => $v !== null && $v !== ''),
            );
        }
        if (isset($data['payment_method_accounts']) && is_array($data['payment_method_accounts'])) {
            $merged['payment_method_accounts'] = array_merge(
                is_array($current['payment_method_accounts'] ?? null) ? $current['payment_method_accounts'] : [],
                array_filter($data['payment_method_accounts'], fn ($v) => $v !== null && $v !== ''),
            );
        }
        $moduleSettings['accounting'] = $merged;
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
