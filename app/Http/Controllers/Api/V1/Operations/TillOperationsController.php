<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Till;
use App\Models\TillFloatSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TillOperationsController extends Controller
{
    /** @param  mixed  $breakdown */
    protected function normalizeFloatEntries($breakdown): array
    {
        if (! is_array($breakdown) || $breakdown === []) {
            return [];
        }

        if (array_is_list($breakdown)) {
            return array_values(array_map(function ($entry) {
                if (! is_array($entry)) {
                    return null;
                }

                return [
                    'new_float' => (float) ($entry['new_float'] ?? 0),
                    'payment_type' => strtoupper((string) ($entry['payment_type'] ?? 'CASH')),
                    'date_added' => $entry['date_added'] ?? now()->format('Y-m-d\TH:i:s.v'),
                ];
            }, array_filter($breakdown)));
        }

        $entries = [];
        foreach ($breakdown as $type => $amount) {
            if (is_numeric($amount)) {
                $entries[] = [
                    'new_float' => (float) $amount,
                    'payment_type' => strtoupper((string) $type),
                    'date_added' => now()->format('Y-m-d\TH:i:s.v'),
                ];
            }
        }

        return $entries;
    }

    protected function appendFloatEntry(array $entries, float $amount, string $paymentType): array
    {
        $entries[] = [
            'new_float' => $amount,
            'payment_type' => strtoupper($paymentType),
            'date_added' => now()->format('Y-m-d\TH:i:s.v'),
        ];

        return $entries;
    }

    protected function sumFloatEntries(array $entries): float
    {
        return array_sum(array_map(
            fn (array $entry) => (float) ($entry['new_float'] ?? 0),
            $entries,
        ));
    }

    protected function assertTillAssignedToCashier(Till $till, int $userId): void
    {
        if ($till->cashier_id !== null && (int) $till->cashier_id !== $userId) {
            throw new InvalidArgumentException('This till is assigned to another cashier.');
        }
    }

    protected function assertCashierUsesAssignedTill(int $userId, int $tillId): void
    {
        $assignedTill = Till::query()
            ->where('cashier_id', $userId)
            ->where('is_active', true)
            ->first();

        if ($assignedTill && (int) $assignedTill->id !== $tillId) {
            throw new InvalidArgumentException(
                'You are already assigned to '.$assignedTill->till_number.'. Use your assigned till.',
            );
        }
    }

    protected function assertCashierHasNoOtherOpenSession(int $userId, int $tillId): void
    {
        $open = TillFloatSession::query()
            ->where('cashier_id', $userId)
            ->where('status', 'open')
            ->first();

        if ($open && (int) $open->till_id !== $tillId) {
            throw new InvalidArgumentException(
                'You already have an open session on another till. Close it before opening a new one.',
            );
        }
    }

    public function openSession(Request $request)
    {
        $data = $request->validate([
            'till_id' => 'required|integer',
            'branch_id' => 'required|integer',
            'working_amount' => 'required|numeric|min:0',
            'payment_type' => 'required|string|max:45',
            'float_breakdown' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $existing = TillFloatSession::query()
            ->where('till_id', $data['till_id'])
            ->where('status', 'open')
            ->first();
        if ($existing) {
            if ((int) $existing->cashier_id === (int) $request->user()->id) {
                return response()->json($existing->fresh());
            }

            throw new InvalidArgumentException(
                'This till already has an open session by another cashier. Close that session first or choose another till.',
            );
        }

        $till = Till::findOrFail($data['till_id']);
        $userId = (int) $request->user()->id;

        $this->assertTillAssignedToCashier($till, $userId);
        $this->assertCashierUsesAssignedTill($userId, (int) $till->id);
        $this->assertCashierHasNoOtherOpenSession($userId, (int) $till->id);

        $amount = (float) $data['working_amount'];
        if ($amount <= 0) {
            throw new InvalidArgumentException('Operating float is required to open a session.');
        }

        $entries = $data['float_breakdown'] ?? null;
        $floatBreakdown = is_array($entries) && $entries !== []
            ? $this->normalizeFloatEntries($entries)
            : $this->appendFloatEntry([], $amount, $data['payment_type']);

        $session = TillFloatSession::create([
            'till_id' => $data['till_id'],
            'branch_id' => $data['branch_id'],
            'working_amount' => (int) round($this->sumFloatEntries($floatBreakdown)),
            'float_breakdown' => $floatBreakdown,
            'notes' => $data['notes'] ?? null,
            'cashier_id' => $userId,
            'session_date' => now()->toDateString(),
            'status' => 'open',
        ]);

        if ($till->cashier_id === null) {
            $till->update(['cashier_id' => $userId]);
        }

        return response()->json($session, 201);
    }

    public function addFloat(Request $request, int $sessionId)
    {
        $data = $request->validate([
            'new_float' => 'required|numeric|min:0.01',
            'payment_type' => 'required|string|max:45',
        ]);

        $session = TillFloatSession::findOrFail($sessionId);
        if ($session->status !== 'open') {
            throw new InvalidArgumentException('Cannot add float to a closed session.');
        }

        if ((int) $session->cashier_id !== (int) $request->user()->id) {
            throw new InvalidArgumentException('Only the session cashier can add float.');
        }

        $entries = $this->normalizeFloatEntries($session->float_breakdown);
        $entries = $this->appendFloatEntry($entries, (float) $data['new_float'], $data['payment_type']);
        $total = $this->sumFloatEntries($entries);

        $session->update([
            'float_breakdown' => $entries,
            'working_amount' => (int) round($total),
        ]);

        return response()->json($session->fresh());
    }

    public function closeSession(Request $request, int $sessionId)
    {
        $data = $request->validate([
            'closing_amount' => 'required|numeric',
            'expected_amount' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        $session = TillFloatSession::findOrFail($sessionId);
        if ($session->status !== 'open') {
            throw new InvalidArgumentException('Session is not open.');
        }

        $report = $this->buildTillReport($sessionId);
        $expected = $data['expected_amount'] ?? ($report['expected_cash'] ?? null);
        $cashSales = (float) ($report['sales']['cash'] ?? 0);

        $session->update([
            'closing_amount' => $data['closing_amount'],
            'expected_amount' => $expected,
            'notes' => $data['notes'] ?? $session->notes,
            'cash_sales' => $cashSales,
            'closed_at' => now(),
            'status' => 'closed',
        ]);

        $fresh = $session->fresh();
        $variance = $expected !== null
            ? (float) $data['closing_amount'] - (float) $expected
            : null;

        return response()->json([
            'session' => $fresh,
            'variance' => $variance,
            'report' => $this->buildTillReport($sessionId, $fresh),
        ]);
    }

    public function xReport(int $sessionId)
    {
        $report = $this->buildTillReport($sessionId);
        if (! $report) {
            abort(404);
        }

        return response()->json(['type' => 'X', ...$report]);
    }

    public function zReport(int $sessionId)
    {
        $session = TillFloatSession::findOrFail($sessionId);
        if ($session->status !== 'closed') {
            return response()->json(['message' => 'Session must be closed for Z-report.'], 422);
        }

        $report = $this->buildTillReport($sessionId, $session);
        if (! $report) {
            abort(404);
        }

        $variance = null;
        if ($session->expected_amount !== null && $session->closing_amount !== null) {
            $variance = (float) $session->closing_amount - (float) $session->expected_amount;
        }

        return response()->json([
            'type' => 'Z',
            'session' => $session,
            'variance' => $variance,
            ...$report,
        ]);
    }

    protected function buildTillReport(int $floatSessionId, ?TillFloatSession $sessionModel = null): ?array
    {
        $session = $sessionModel
            ? (object) $sessionModel->toArray()
            : DB::table('till_float_sessions')->where('id', $floatSessionId)->first();
        if (! $session) {
            return null;
        }

        $salesAgg = DB::table('sales')
            ->where('float_session_id', $floatSessionId)
            ->where('status', 'completed')
            ->selectRaw('
                COUNT(*) as transactions,
                COALESCE(SUM(order_total), 0) as gross,
                COALESCE(SUM(order_discount), 0) as discounts,
                COALESCE(SUM(cash), 0) as cash,
                COALESCE(SUM(mpesa_amount), 0) as mpesa,
                COALESCE(SUM(equity_amount), 0) as equity,
                COALESCE(SUM(kcb_amount), 0) as kcb
            ')
            ->first();

        $saleIds = DB::table('sales')
            ->where('float_session_id', $floatSessionId)
            ->where('status', 'completed')
            ->pluck('id');

        $refunds = $saleIds->isEmpty()
            ? 0
            : (float) DB::table('returns')->whereIn('sale_id', $saleIds)->sum('amount');

        $gross = (float) ($salesAgg->gross ?? 0);
        $discounts = (float) ($salesAgg->discounts ?? 0);
        $cash = (float) ($salesAgg->cash ?? 0);
        $mpesa = (float) ($salesAgg->mpesa ?? 0);
        $equity = (float) ($salesAgg->equity ?? 0);
        $kcb = (float) ($salesAgg->kcb ?? 0);
        $bank = $equity + $kcb;
        $net = max(0, $gross - $discounts - $refunds);
        $openingFloat = (float) ($session->working_amount ?? 0);
        $expectedCash = $openingFloat + $cash;

        $floatEntries = $this->normalizeFloatEntries(
            is_string($session->float_breakdown ?? null)
                ? json_decode($session->float_breakdown, true)
                : ($session->float_breakdown ?? []),
        );

        return [
            'session' => $session,
            'float_entries' => $floatEntries,
            'sales' => [
                'transactions' => (int) ($salesAgg->transactions ?? 0),
                'gross' => $gross,
                'discounts' => $discounts,
                'refunds' => $refunds,
                'net' => $net,
                'cash' => $cash,
                'mpesa' => $mpesa,
                'bank' => $bank,
                'equity' => $equity,
                'kcb' => $kcb,
            ],
            'expected_cash' => $expectedCash,
        ];
    }
}
