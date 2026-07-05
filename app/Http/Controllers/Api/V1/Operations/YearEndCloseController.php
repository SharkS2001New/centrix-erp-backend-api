<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Accounting\YearEndCloseService;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\InAppNotificationEvents;
use Illuminate\Http\Request;

class YearEndCloseController extends Controller
{
    public function __construct(
        protected YearEndCloseService $yearEnd,
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $orgId = (int) $request->user()->organization_id;
        $result = $this->yearEnd->closeYear($orgId, $request->user(), (int) $data['year']);
        app(AdminNotificationService::class)->notifyPermission($request->user(), 'accounting.manage', [
            'type' => 'info',
            'severity' => 'danger',
            'title' => 'Year-end close completed',
            'message' => ($request->user()->full_name ?: $request->user()->username)." completed year-end close for {$data['year']}.",
            'action_url' => '/accounting/fiscal-periods',
        ], InAppNotificationEvents::YEAR_END_CLOSE);

        return response()->json([
            'entry' => $result['entry'],
            'net_income' => $result['net_income'],
            'revenue_total' => $result['revenue_total'],
            'expense_total' => $result['expense_total'],
        ], 201);
    }
}
