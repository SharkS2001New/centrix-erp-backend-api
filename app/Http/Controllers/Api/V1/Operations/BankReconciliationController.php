<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Accounting\BankReconciliationService;
use App\Services\Accounting\BankStatementImportService;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\InAppNotificationEvents;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BankReconciliationController extends Controller
{
    public function __construct(
        protected BankReconciliationService $reconciliations,
        protected BankStatementImportService $statementImports,
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

    /**
     * Parse a statement file/CSV without writing — review grid + tie-out.
     */
    public function previewStatement(Request $request)
    {
        $request->validate([
            'file' => 'nullable|file|max:12288',
            'csv' => 'nullable|string',
            'format' => 'nullable|in:csv,pdf,ofx,qfx',
        ]);

        if (! $request->hasFile('file') && ! $request->filled('csv')) {
            return response()->json(['message' => 'Upload a statement file or paste CSV text.'], 422);
        }

        try {
            $preview = $this->statementImports->previewFromUpload(
                file: $request->file('file'),
                csv: $request->input('csv'),
                formatHint: $request->input('format'),
                organizationId: (int) $request->user()->organization_id,
                storeFile: false,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($preview);
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
            'file' => 'nullable|file|max:12288',
            'format' => 'nullable|in:csv,pdf,ofx,qfx',
            'confirm_unbalanced' => 'nullable|boolean',
        ]);

        $lines = $data['statement_lines'] ?? [];
        $filename = $data['imported_filename'] ?? null;
        $requireTieOut = ! $request->boolean('confirm_unbalanced');

        try {
            if ($request->hasFile('file') || ! empty($data['csv'])) {
                $preview = $this->statementImports->previewFromUpload(
                    file: $request->file('file'),
                    csv: $data['csv'] ?? null,
                    formatHint: $data['format'] ?? null,
                    organizationId: (int) $request->user()->organization_id,
                    storeFile: $request->hasFile('file'),
                );
                $lines = array_merge(
                    $lines,
                    $this->statementImports->linesFromPreview($preview, $requireTieOut),
                );
                $filename = $filename ?: ($preview['filename'] ?? null);
                if (! empty($preview['closing_balance']) && ! $request->filled('statement_balance')) {
                    $data['statement_balance'] = $preview['closing_balance'];
                }
                if (! empty($preview['opening_balance']) && ! isset($data['opening_balance'])) {
                    $data['opening_balance'] = $preview['opening_balance'];
                }
                if (! empty($preview['period_end']) && empty($data['period_end'])) {
                    $data['period_end'] = $preview['period_end'];
                }
            } elseif (! empty($data['csv'])) {
                $parsed = $this->reconciliations->parseCsvRows($data['csv']);
                $this->reconciliations->assertCsvParsed($data['csv'], $parsed);
                $lines = array_merge($lines, $parsed);
            }

            $data['imported_filename'] = $filename;
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

    public function applySuggestedMatches(Request $request, int $reconciliationId)
    {
        $data = $request->validate([
            'min_confidence' => 'nullable|integer|min:0|max:100',
        ]);

        try {
            $result = $this->reconciliations->applySuggestedMatches(
                (int) $request->user()->organization_id,
                $reconciliationId,
                $request->user(),
                (int) ($data['min_confidence'] ?? 70),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'applied' => $result['applied'],
            'skipped' => $result['skipped'],
            ...$result['payload'],
        ]);
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
            'file' => 'nullable|file|max:12288',
            'format' => 'nullable|in:csv,pdf,ofx,qfx',
            'confirm_unbalanced' => 'nullable|boolean',
            'imported_filename' => 'nullable|string|max:255',
        ]);

        $lines = $data['statement_lines'] ?? [];
        $requireTieOut = ! $request->boolean('confirm_unbalanced');
        $orgId = (int) $request->user()->organization_id;

        try {
            if ($request->hasFile('file')) {
                $preview = $this->statementImports->previewFromUpload(
                    file: $request->file('file'),
                    csv: null,
                    formatHint: $data['format'] ?? null,
                    organizationId: $orgId,
                    storeFile: true,
                );
                $lines = array_merge(
                    $lines,
                    $this->statementImports->linesFromPreview($preview, $requireTieOut),
                );
                $filename = $data['imported_filename'] ?? ($preview['filename'] ?? null);
                if ($filename) {
                    \App\Models\BankReconciliation::query()
                        ->where('organization_id', $orgId)
                        ->where('id', $reconciliationId)
                        ->update(['imported_filename' => $filename]);
                }
            }

            $payload = $this->reconciliations->importStatement(
                $orgId,
                $reconciliationId,
                $lines,
                $request->hasFile('file') ? null : ($data['csv'] ?? null),
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

    public function destroy(Request $request, int $reconciliationId)
    {
        try {
            $this->reconciliations->destroy(
                (int) $request->user()->organization_id,
                $reconciliationId,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }
}
