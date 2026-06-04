<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\TillFloatSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TillOperationsController extends Controller
{
    public function openSession(Request $request)
    {
        $data = $request->validate([
            'till_id' => 'required|integer',
            'branch_id' => 'required|integer',
            'working_amount' => 'required|integer|min:0',
            'float_breakdown' => 'nullable|array',
        ]);

        $session = TillFloatSession::create([
            ...$data,
            'cashier_id' => $request->user()->id,
            'session_date' => now()->toDateString(),
            'status' => 'open',
            'float_breakdown' => $data['float_breakdown'] ?? ['CASH' => $data['working_amount']],
        ]);

        return response()->json($session, 201);
    }

    public function closeSession(Request $request, int $sessionId)
    {
        $data = $request->validate([
            'closing_amount' => 'required|numeric',
            'expected_amount' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        $session = TillFloatSession::findOrFail($sessionId);
        $cashSales = DB::table('sales')
            ->where('float_session_id', $sessionId)
            ->where('status', 'completed')
            ->sum('cash');

        $session->update([
            ...$data,
            'cash_sales' => $cashSales,
            'closed_at' => now(),
            'status' => 'closed',
        ]);

        return response()->json($session);
    }

    public function xReport(int $sessionId)
    {
        return response()->json($this->buildTillReport($sessionId));
    }

    public function zReport(int $sessionId)
    {
        $session = TillFloatSession::findOrFail($sessionId);
        if ($session->status !== 'closed') {
            return response()->json(['message' => 'Session must be closed for Z-report.'], 422);
        }

        return response()->json([
            'session' => $session,
            'report' => $this->buildTillReport($sessionId),
            'type' => 'Z',
        ]);
    }

    protected function buildTillReport(int $floatSessionId): ?array
    {
        $session = DB::table('till_float_sessions')->where('id', $floatSessionId)->first();
        if (! $session) {
            return null;
        }

        $sales = DB::table('sales')
            ->where('float_session_id', $floatSessionId)
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) as transactions, SUM(order_total) as gross, SUM(cash) as cash, SUM(mpesa_amount) as mpesa, SUM(equity_amount) as equity, SUM(kcb_amount) as kcb')
            ->first();

        return ['session' => $session, 'sales' => $sales];
    }
}
