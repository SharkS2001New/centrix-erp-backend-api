<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Till;
use App\Models\TillFloatSession;
use App\Services\Accounting\ExpenseJournalService;
use App\Services\Audit\OperationalAuditService;
use App\Services\Erp\ErpContext;
use App\Services\Erp\FloatSessionValidator;
use App\Services\Erp\TillSessionAuthorization;
use App\Services\Erp\TillVarianceJournal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TillOperationsController extends Controller
{
    use HandlesBranchScope;

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
        $other = TillFloatSession::query()
            ->where('cashier_id', $userId)
            ->whereIn('status', ['open', 'suspended'])
            ->where('till_id', '!=', $tillId)
            ->first();

        if ($other) {
            throw new InvalidArgumentException(
                'You already have an active session on another till. Close or resume it before opening a new one.',
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

        $data['branch_id'] = $this->userAccess()->resolveBranchId($request->user(), (int) $data['branch_id']);

        $existing = TillFloatSession::query()
            ->where('till_id', $data['till_id'])
            ->whereIn('status', ['open', 'suspended'])
            ->first();
        if ($existing) {
            if ((int) $existing->cashier_id === (int) $request->user()->id) {
                if ($existing->status === 'suspended') {
                    $existing->update([
                        'status' => 'open',
                        'suspended_at' => null,
                    ]);

                    return response()->json($existing->fresh());
                }

                return response()->json($existing->fresh());
            }

            throw new InvalidArgumentException(
                'This till already has an open session by another cashier. Close that session first or choose another till.',
            );
        }

        $till = $this->findBranchScopedModel(Till::class, (int) $data['till_id'], $request->user());
        $userId = (int) $request->user()->id;

        $this->assertTillAssignedToCashier($till, $userId);
        $this->assertCashierUsesAssignedTill($userId, (int) $till->id);
        $this->assertCashierHasNoOtherOpenSession($userId, (int) $till->id);

        $amount = (float) $data['working_amount'];
        $validator = FloatSessionValidator::forUser($request->user());
        $requireFloat = $validator->tillFloatEnabled();
        if (! $requireFloat && $amount > 0) {
            throw new InvalidArgumentException('Operating float is disabled for this organization.');
        }
        if ($requireFloat && $amount <= 0) {
            throw new InvalidArgumentException('Operating float is required to open a session.');
        }

        $entries = $data['float_breakdown'] ?? null;
        if (! $requireFloat) {
            $floatBreakdown = [];
        } elseif (is_array($entries) && $entries !== []) {
            $floatBreakdown = $this->normalizeFloatEntries($entries);
        } else {
            $floatBreakdown = $amount > 0
                ? $this->appendFloatEntry([], $amount, $data['payment_type'])
                : [];
        }

        $session = TillFloatSession::create([
            'organization_id' => (int) ($till->organization_id
                ?: \App\Support\OrganizationIdResolver::requireForBranch((int) $data['branch_id'])),
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

        $session = $this->findScopedTillSession($sessionId, $request->user());
        TillSessionAuthorization::assertSessionCashier($request->user(), $session);
        if (! FloatSessionValidator::forUser($request->user())->tillFloatEnabled()) {
            throw new InvalidArgumentException('Operating float is disabled for this organization.');
        }
        if ($session->status !== 'open') {
            throw new InvalidArgumentException('Cannot add float to a closed session.');
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

    public function recordCashMovement(Request $request, int $sessionId)
    {
        $data = $request->validate([
            'type' => 'required|string|in:drop,pay_in,pay_out',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        $session = $this->findScopedTillSession($sessionId, $request->user());
        TillSessionAuthorization::assertSessionCashier($request->user(), $session);
        if ($session->status !== 'open') {
            throw new InvalidArgumentException('Cannot record cash movements on a closed session.');
        }

        $movements = $this->normalizeCashMovements($session->cash_movements);
        $movements[] = [
            'type' => $data['type'],
            'amount' => (float) $data['amount'],
            'reason' => $data['reason'] ?? null,
            'recorded_at' => now()->format('Y-m-d\TH:i:s.v'),
            'recorded_by' => (int) $request->user()->id,
        ];

        $session->update(['cash_movements' => $movements]);

        return response()->json($session->fresh());
    }

    public function expenseGroups(Request $request)
    {
        $rows = DB::table('expense_groups')
            ->select('id', 'group_name')
            ->orderBy('group_name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function recordSessionExpense(Request $request, int $sessionId)
    {
        $data = $request->validate([
            'expense_group_id' => 'required|integer',
            'expense_amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:200',
            'payment_method_id' => 'required|integer',
        ]);

        $session = $this->findScopedTillSession($sessionId, $request->user());
        TillSessionAuthorization::assertSessionCashier($request->user(), $session);
        if ($session->status !== 'open') {
            throw new InvalidArgumentException('Cannot record expenses on a closed session.');
        }

        $expense = Expense::create([
            'organization_id' => (int) (
                $request->user()->organization_id
                ?? \App\Support\OrganizationIdResolver::requireForBranch((int) $session->branch_id)
            ),
            'branch_id' => $session->branch_id,
            'expense_group_id' => $data['expense_group_id'],
            'float_session_id' => $session->id,
            'description' => $data['description'] ?? null,
            'expense_amount' => $data['expense_amount'],
            'expense_date' => now()->toDateString(),
            'payment_method_id' => $data['payment_method_id'],
            'recorded_by' => $request->user()->id,
        ]);

        $gate = app(ErpContext::class)->gateForUser($request->user());
        app(ExpenseJournalService::class)->postIfEnabled($expense, $request->user(), $gate);
        app(OperationalAuditService::class)->logTillExpense($request->user(), (int) $expense->id, [
            'branch_id' => (int) $session->branch_id,
            'float_session_id' => (int) $session->id,
            'expense_group_id' => (int) $data['expense_group_id'],
            'expense_amount' => (float) $data['expense_amount'],
            'payment_method_id' => (int) $data['payment_method_id'],
            'description' => $data['description'] ?? null,
        ]);

        return response()->json($expense, 201);
    }

    public function closeSession(Request $request, int $sessionId)
    {
        $data = $request->validate([
            'closing_amount' => 'required|numeric',
            'expected_amount' => 'nullable|numeric',
            'closing_denominations' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $session = $this->findScopedTillSession($sessionId, $request->user());
        TillSessionAuthorization::assertSessionCashier($request->user(), $session);
        if ($session->status !== 'open') {
            throw new InvalidArgumentException('Session is not open.');
        }

        $report = $this->buildTillReport($sessionId);
        $expected = $data['expected_amount'] ?? ($report['expected_cash'] ?? null);
        $cashSales = (float) ($report['sales']['cash'] ?? 0);
        $expensesTotal = (float) DB::table('expenses')
            ->where('float_session_id', $sessionId)
            ->whereNull('deleted_at')
            ->sum('expense_amount');

        $session->update([
            'closing_amount' => $data['closing_amount'],
            'expected_amount' => $expected,
            'notes' => $data['notes'] ?? $session->notes,
            'cash_sales' => $cashSales,
            'expenses_total' => $expensesTotal,
            'closing_denominations' => $data['closing_denominations'] ?? null,
            'closed_at' => now(),
            'status' => 'closed',
        ]);

        $fresh = $session->fresh();
        $variance = $expected !== null
            ? (float) $data['closing_amount'] - (float) $expected
            : null;

        $journal = app(TillVarianceJournal::class)->postIfEnabled($fresh, $request->user(), $variance);

        return response()->json([
            'session' => $fresh,
            'variance' => $variance,
            'variance_journal_id' => $journal?->id,
            'report' => $this->buildTillReport($sessionId, $fresh),
        ]);
    }

    public function suspendSession(Request $request, int $sessionId)
    {
        $session = $this->findScopedTillSession($sessionId, $request->user());
        TillSessionAuthorization::assertSessionCashier($request->user(), $session);
        if ($session->status !== 'open') {
            throw new InvalidArgumentException('Only an open session can be suspended.');
        }

        $session->update([
            'status' => 'suspended',
            'suspended_at' => now(),
        ]);

        return response()->json($session->fresh());
    }

    public function resumeSession(Request $request, int $sessionId)
    {
        $session = $this->findScopedTillSession($sessionId, $request->user());
        TillSessionAuthorization::assertSessionCashier($request->user(), $session);
        if ($session->status !== 'suspended') {
            throw new InvalidArgumentException('Session is not suspended.');
        }

        $otherOpen = TillFloatSession::query()
            ->where('cashier_id', $session->cashier_id)
            ->where('status', 'open')
            ->where('id', '!=', $session->id)
            ->exists();
        if ($otherOpen) {
            throw new InvalidArgumentException('Close your other open session before resuming this one.');
        }

        $session->update([
            'status' => 'open',
            'suspended_at' => null,
        ]);

        return response()->json($session->fresh());
    }

    public function handoverSession(Request $request, int $sessionId)
    {
        $data = $request->validate([
            'to_cashier_id' => 'required|integer',
            'notes' => 'nullable|string|max:500',
        ]);

        $session = $this->findScopedTillSession($sessionId, $request->user());
        TillSessionAuthorization::assertCanHandover($request->user(), $session);
        if ($session->status !== 'open') {
            throw new InvalidArgumentException('Only an open session can be handed over.');
        }

        $toCashier = User::query()
            ->where('id', $data['to_cashier_id'])
            ->where('organization_id', $request->user()->organization_id)
            ->first();
        if (! $toCashier) {
            throw new InvalidArgumentException('Target cashier not found.');
        }
        if ((int) $toCashier->id === (int) $session->cashier_id) {
            throw new InvalidArgumentException('Session is already assigned to that cashier.');
        }
        if ($toCashier->branch_id && (int) $toCashier->branch_id !== (int) $session->branch_id) {
            throw new InvalidArgumentException('Target cashier must belong to the same branch.');
        }

        $conflict = TillFloatSession::query()
            ->where('cashier_id', $toCashier->id)
            ->where('status', 'open')
            ->exists();
        if ($conflict) {
            throw new InvalidArgumentException('Target cashier already has an open session.');
        }

        $fromCashierId = (int) $session->cashier_id;
        $handoverNote = trim((string) ($data['notes'] ?? ''));
        $noteLine = sprintf(
            'Handed over from user #%d to user #%d at %s%s',
            $fromCashierId,
            $toCashier->id,
            now()->format('Y-m-d H:i'),
            $handoverNote !== '' ? ': '.$handoverNote : '',
        );
        $notes = trim(($session->notes ? $session->notes."\n" : '').$noteLine);

        $session->update([
            'cashier_id' => $toCashier->id,
            'handed_over_from' => $fromCashierId,
            'handed_over_at' => now(),
            'notes' => $notes,
        ]);

        $till = Till::find($session->till_id);
        if ($till) {
            $till->update(['cashier_id' => $toCashier->id]);
        }

        return response()->json([
            'session' => $session->fresh(),
            'from_cashier_id' => $fromCashierId,
            'to_cashier_id' => $toCashier->id,
        ]);
    }

    public function xReport(Request $request, int $sessionId)
    {
        $session = $this->findScopedTillSession($sessionId, $request->user());
        TillSessionAuthorization::assertCanView($request->user(), $session);

        $report = $this->buildTillReport($sessionId, $session);
        if (! $report) {
            abort(404);
        }

        return response()->json([
            'type' => 'X',
            'session' => $session,
            'report' => $report,
        ]);
    }

    public function zReport(Request $request, int $sessionId)
    {
        $session = $this->findScopedTillSession($sessionId, $request->user());
        TillSessionAuthorization::assertCanView($request->user(), $session);
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
            'report' => $report,
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
                COALESCE(SUM(total_vat), 0) as total_vat,
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
        $cashBreakdown = $this->sessionCashCollected($floatSessionId);
        $cash = (float) ($cashBreakdown['cash'] ?? 0);
        $debtorCollections = (float) ($cashBreakdown['debtor_collections'] ?? 0);
        $mpesa = (float) ($salesAgg->mpesa ?? 0);
        $equity = (float) ($salesAgg->equity ?? 0);
        $kcb = (float) ($salesAgg->kcb ?? 0);
        $bank = $equity + $kcb;
        $netSales = max(0, $gross - $refunds);
        $totalVat = round((float) ($salesAgg->total_vat ?? 0), 2);
        $openingFloat = (float) ($session->working_amount ?? 0);
        $netSalesMinusFloat = max(0, round($netSales - $openingFloat, 2));
        $grossTillTotal = $openingFloat + $cash;
        $cashMovements = $this->normalizeCashMovements(
            is_string($session->cash_movements ?? null)
                ? json_decode($session->cash_movements, true)
                : ($session->cash_movements ?? []),
        );
        $movementAdjust = $this->sumCashMovementAdjustments($cashMovements);
        $sessionExpenses = (float) DB::table('expenses')
            ->where('float_session_id', $floatSessionId)
            ->whereNull('deleted_at')
            ->sum('expense_amount');
        $expectedCash = $openingFloat + $cash - $movementAdjust['out'] + $movementAdjust['in'] - $sessionExpenses;

        $floatEntries = $this->normalizeFloatEntries(
            is_string($session->float_breakdown ?? null)
                ? json_decode($session->float_breakdown, true)
                : ($session->float_breakdown ?? []),
        );

        $payments = $this->buildPaymentSummary($floatSessionId, $cash, $mpesa, $bank);

        return [
            'session' => $session,
            'float_entries' => $floatEntries,
            'sales' => [
                'transactions' => (int) ($salesAgg->transactions ?? 0),
                'net_sales' => $netSales,
                'net' => $netSales,
                'net_sales_minus_float' => $netSalesMinusFloat,
                'total_vat' => $totalVat,
                'order_discounts' => $discounts,
                'refunds' => $refunds,
                'cash' => $cash,
                'debtor_collections' => $debtorCollections,
                'mpesa' => $mpesa,
                'bank' => $bank,
                'equity' => $equity,
                'kcb' => $kcb,
            ],
            'till' => [
                'opening_float' => $openingFloat,
                'cash_collected' => $cash,
                'gross_total' => $grossTillTotal,
                'session_expenses' => $sessionExpenses,
            ],
            'payments' => $payments,
            'expected_cash' => $expectedCash,
            'cash_movements' => $cashMovements,
            'session_expenses' => $sessionExpenses,
        ];
    }

    /** @return array{cash: float, debtor_collections: float} */
    protected function sessionCashCollected(int $floatSessionId): array
    {
        $fromPayments = (float) DB::table('sale_payments as sp')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->where('sp.float_session_id', $floatSessionId)
            ->where('pm.method_code', 'CASH')
            ->sum('sp.amount');

        $legacyCash = (float) DB::table('sales as s')
            ->where('s.float_session_id', $floatSessionId)
            ->where('s.status', 'completed')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('sale_payments as sp')
                    ->whereColumn('sp.sale_id', 's.id')
                    ->whereNotNull('sp.float_session_id');
            })
            ->sum('s.cash');

        $cash = $fromPayments + $legacyCash;

        $debtorCollections = (float) DB::table('sale_payments as sp')
            ->join('sales as s', 's.id', '=', 'sp.sale_id')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->where('sp.float_session_id', $floatSessionId)
            ->where('pm.method_code', 'CASH')
            ->where(function ($query) use ($floatSessionId) {
                $query->whereNull('s.float_session_id')
                    ->orWhere('s.float_session_id', '!=', $floatSessionId);
            })
            ->sum('sp.amount');

        return [
            'cash' => $cash,
            'debtor_collections' => $debtorCollections,
        ];
    }

    /** @return list<array{method_code: string, method_name: string, total: float}> */
    protected function buildPaymentSummary(int $floatSessionId, float $cash, float $mpesa, float $bank): array
    {
        $rows = DB::table('sale_payments as sp')
            ->join('sales as s', 's.id', '=', 'sp.sale_id')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->where('s.float_session_id', $floatSessionId)
            ->where('s.status', 'completed')
            ->selectRaw('pm.method_code, pm.method_name, COALESCE(SUM(sp.amount), 0) as total')
            ->groupBy('pm.method_code', 'pm.method_name')
            ->orderByDesc('total')
            ->get();

        if ($rows->isNotEmpty()) {
            return $rows->map(fn ($row) => [
                'method_code' => (string) $row->method_code,
                'method_name' => (string) $row->method_name,
                'total' => (float) $row->total,
            ])->all();
        }

        $fallback = [];
        if ($cash > 0) {
            $fallback[] = ['method_code' => 'CASH', 'method_name' => 'Cash', 'total' => $cash];
        }
        if ($mpesa > 0) {
            $fallback[] = ['method_code' => 'MPESA', 'method_name' => 'M-Pesa', 'total' => $mpesa];
        }
        if ($bank > 0) {
            $fallback[] = ['method_code' => 'BANK', 'method_name' => 'Bank', 'total' => $bank];
        }

        return $fallback;
    }

    /** @param  mixed  $movements */
    protected function normalizeCashMovements($movements): array
    {
        if (! is_array($movements)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($row) {
            if (! is_array($row)) {
                return null;
            }
            $type = strtolower((string) ($row['type'] ?? ''));
            if (! in_array($type, ['drop', 'pay_in', 'pay_out'], true)) {
                return null;
            }

            return [
                'type' => $type,
                'amount' => (float) ($row['amount'] ?? 0),
                'reason' => $row['reason'] ?? null,
                'recorded_at' => $row['recorded_at'] ?? null,
                'recorded_by' => $row['recorded_by'] ?? null,
            ];
        }, $movements)));
    }

    /** @return array{in: float, out: float} */
    protected function sumCashMovementAdjustments(array $movements): array
    {
        $in = 0.0;
        $out = 0.0;
        foreach ($movements as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            if ($row['type'] === 'pay_in') {
                $in += $amount;
            } else {
                $out += $amount;
            }
        }

        return ['in' => $in, 'out' => $out];
    }
}
