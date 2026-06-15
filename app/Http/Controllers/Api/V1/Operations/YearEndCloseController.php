<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Accounting\YearEndCloseService;
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

        return response()->json([
            'entry' => $result['entry'],
            'net_income' => $result['net_income'],
            'revenue_total' => $result['revenue_total'],
            'expense_total' => $result['expense_total'],
        ], 201);
    }
}
