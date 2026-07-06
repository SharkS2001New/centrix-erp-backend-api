<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Accounting\BankReconciliationService;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\InAppNotificationEvents;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BankReconciliationController extends Controller
{
    public function __construct(
        protected BankReconciliationService $reconciliations,
    ) {}

    public function bankAccounts(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => $this->reconciliations->listBankAccounts($orgId)->values(),
        ]);
    }

    public function index(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => $this->reconciliations->listForOrganization($orgId)->values(),
        ]);
    }

    public function show(Request $request, int $reconciliationId)
    {
        $orgId = (int) $request->user()->organization_id;

        return response()->json(
            $this->reconciliations->show($orgId, $reconciliationId),
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'chart_of_account_id' => 'required|integer',
            'title' => 'nullable|string|max:120',
            'period_start' => 'nullable|date',
            'period_end' => 'required|date',
            'opening_balance' => 'nullable|numeric',
            'statement_balance' => 'required|numeric',
            'notes' => 'nullable|string',
            'imported_filename' => 'nullable|string|max:255',
            'statement_lines' => 'nullable|array',
            'statement_lines.*.line_date' => 'nullable|date',
            'statement_lines.*.date' => 'nullable|date',
            'statement_lines.*.description' => 'nullable|string|max:500',
            'statement_lines.*.reference' => 'nullable|string|max:120',
            'statement_lines.*.amount' => 'nullable|numeric',
            'statement_lines.*.debit' => 'nullable|numeric',
            'statement_lines.*.credit' => 'nullable|numeric',
            'csv' => 'nullable|string',
        ]);

        $lines = $data['statement_lines'] ?? [];
        if (! empty($data['csv'])) {
            $lines = array_merge($lines, $this->reconciliations->parseCsvRows($data['csv']));
        }

        try {
            $reconciliation = $this->reconciliations->create($request->user(), $data, $lines);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($reconciliation, 201);
    }

    public function applyMatch(Request $request, int $reconciliationId)
    {
        $data = $request->validate([
            'bank_statement_line_id' => 'required|integer',
            'journal_entry_line_id' => 'required|integer',
            'match_type' => 'nullable|in:auto,manual',
        ]);

        try {
            $reconciliation = $this->reconciliations->applyMatch(
                (int) $request->user()->organization_id,
                $reconciliationId,
                (int) $data['bank_statement_line_id'],
                (int) $data['journal_entry_line_id'],
                $request->user(),
                $data['match_type'] ?? 'manual',
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($reconciliation);
    }

    public function removeMatch(Request $request, int $reconciliationId, int $matchId)
    {
        try {
            $reconciliation = $this->reconciliations->removeMatch(
                (int) $request->user()->organization_id,
                $reconciliationId,
                $matchId,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($reconciliation);
    }

    public function register(Request $request, int $accountId)
    {
        $data = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);

        $orgId = (int) $request->user()->organization_id;
        $payload = $this->reconciliations->bankRegister(
            $orgId,
            $accountId,
            $data['from_date'] ?? null,
            $data['to_date'] ?? null,
        );

        return response()->json($payload);
    }

    public function excludeStatementLine(Request $request, int $reconciliationId, int $statementLineId)
    {
        try {
            $reconciliation = $this->reconciliations->excludeStatementLine(
                (int) $request->user()->organization_id,
                $reconciliationId,
                $statementLineId,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($reconciliation);
    }

    public function clearBookItem(Request $request, int $reconciliationId)
    {
        $data = $request->validate([
            'journal_entry_line_id' => 'required|integer',
        ]);

        try {
            $reconciliation = $this->reconciliations->clearBookItem(
                (int) $request->user()->organization_id,
                $reconciliationId,
                (int) $data['journal_entry_line_id'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($reconciliation);
    }

    public function createAdjustment(Request $request, int $reconciliationId)
    {
        $data = $request->validate([
            'description' => 'nullable|string|max:255',
            'offset_account_id' => 'nullable|integer',
        ]);

        try {
            $payload = $this->reconciliations->createAdjustment(
                (int) $request->user()->organization_id,
                $reconciliationId,
                $request->user(),
                $data['description'] ?? null,
                isset($data['offset_account_id']) ? (int) $data['offset_account_id'] : null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payload);
    }

    public function importStatement(Request $request, int $reconciliationId)
    {
        $data = $request->validate([
            'statement_lines' => 'nullable|array',
            'statement_lines.*.line_date' => 'nullable|date',
            'statement_lines.*.date' => 'nullable|date',
            'statement_lines.*.description' => 'nullable|string|max:500',
            'statement_lines.*.reference' => 'nullable|string|max:120',
            'statement_lines.*.amount' => 'nullable|numeric',
            'statement_lines.*.debit' => 'nullable|numeric',
            'statement_lines.*.credit' => 'nullable|numeric',
            'csv' => 'nullable|string',
        ]);

        try {
            $payload = $this->reconciliations->importStatement(
                (int) $request->user()->organization_id,
                $reconciliationId,
                $data['statement_lines'] ?? [],
                $data['csv'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payload);
    }

    public function complete(Request $request, int $reconciliationId)
    {
        $data = $request->validate([
            'notes' => 'nullable|string',
        ]);

        try {
            $reconciliation = $this->reconciliations->complete(
                (int) $request->user()->organization_id,
                $reconciliationId,
                $request->user(),
                $data['notes'] ?? null,
            );
            app(AdminNotificationService::class)->notifyPermission($request->user(), 'accounting.manage', [
                'type' => 'info',
                'severity' => 'warning',
                'title' => 'Bank reconciliation completed',
                'message' => ($request->user()->full_name ?: $request->user()->username)." completed bank reconciliation #{$reconciliationId}.",
                'action_url' => "/accounting/bank-reconciliation/{$reconciliationId}",
            ], InAppNotificationEvents::BANK_RECONCILIATION);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($reconciliation);
    }
}
