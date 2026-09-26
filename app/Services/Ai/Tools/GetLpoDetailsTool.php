<?php

namespace App\Services\Ai\Tools;

use App\Models\LpoMst;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\LpoModuleService;
use App\Services\Purchasing\LpoAiDocumentLinks;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Retrieve a purchase order (LPO) for AI: status, lines, next workflow steps, PDF/print links.
 */
class GetLpoDetailsTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected LpoModuleService $lpoModule,
    ) {}

    public function name(): string
    {
        return 'get_lpo_details';
    }

    public function description(): string
    {
        return 'Look up a Centrix purchase order (LPO) by number, reference, or the latest created LPO, '
            .'and return status, supplier, line items, remaining qty, workflow actions, '
            .'and document_links (Open / Print / Download PDF). '
            .'For "last LPO", "latest purchase order", "most recent LPO", or "download the last LPO we created", '
            .'set latest=true (or pass query like "last" / "latest"). '
            .'Use when the user asks to retrieve, show, open, download, or check status of an LPO / purchase order.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lpo_no' => [
                    'type' => 'integer',
                    'description' => 'Exact LPO number (lpo_no) when known.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'PO number, reference, or phrases like "last", "latest", "most recent" when lpo_no is unknown.',
                ],
                'latest' => [
                    'type' => 'boolean',
                    'description' => 'When true, return the most recently created LPO for this organization (highest lpo_no). '
                        .'Use for "last LPO we created" / "download the latest purchase order".',
                ],
            ],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }

        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $orgId = (int) $organization->id;
        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'purchasing.lpo.view', $gate)
            || $this->permissions->hasPermission($user, 'purchasing.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view purchase orders.',
                'screens' => [
                    ['label' => 'Purchase orders (LPO)', 'path' => '/lpo'],
                ],
            ];
        }

        if (! Schema::hasTable('lpo_mst')) {
            return [
                'error' => true,
                'message' => 'Purchase orders are not available in this environment.',
                'screens' => [
                    ['label' => 'Purchase orders (LPO)', 'path' => '/lpo'],
                ],
            ];
        }

        $lpoNo = (int) ($arguments['lpo_no'] ?? 0);
        $query = trim((string) ($arguments['query'] ?? ''));
        $wantsLatest = filter_var($arguments['latest'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || $this->queryMeansLatest($query);

        // Prefer explicit "latest/last" over guessing a number from the sentence (e.g. year 2026).
        if ($wantsLatest) {
            $lpo = $this->latestLpoForOrganization($orgId);
            if (! $lpo) {
                return [
                    'error' => true,
                    'message' => 'No purchase orders found for your organization yet.',
                    'screens' => [
                        ['label' => 'Purchase orders (LPO)', 'path' => '/lpo'],
                        ['label' => 'Create purchase order', 'path' => '/lpo/new'],
                    ],
                ];
            }

            return $this->presentLpo($lpo, $orgId, $user, isLatest: true);
        }

        if ($lpoNo <= 0 && $query !== '') {
            if (preg_match('/\b(\d{1,12})\b/', $query, $m)) {
                $lpoNo = (int) $m[1];
            }
        }

        $lpo = null;
        if ($lpoNo > 0) {
            $lpo = LpoMst::query()
                ->where('organization_id', $orgId)
                ->where('lpo_no', $lpoNo)
                ->whereNull('deleted_at')
                ->first();
        }

        if (! $lpo && $query !== '') {
            $like = '%'.$query.'%';
            $candidates = LpoMst::query()
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($like, $query) {
                    $q->where('reference_number', 'like', $like);
                    if (ctype_digit($query)) {
                        $q->orWhere('lpo_no', (int) $query)
                            ->orWhere('lpo_seq', (int) $query);
                    }
                })
                ->orderByDesc('lpo_no')
                ->limit(8)
                ->get(['lpo_no', 'reference_number', 'lpo_status_code', 'supplier_id', 'total_amount']);

            if ($candidates->count() === 1) {
                $lpo = LpoMst::query()->where('lpo_no', $candidates->first()->lpo_no)->first();
            } elseif ($candidates->isNotEmpty()) {
                return [
                    'error' => true,
                    'message' => 'Several purchase orders matched. Ask the user which LPO number to use.',
                    'candidates' => $candidates->map(fn ($row) => [
                        'lpo_no' => (int) $row->lpo_no,
                        'reference_number' => $row->reference_number,
                        'lpo_status_code' => (int) $row->lpo_status_code,
                        'status_name' => LpoModuleService::statusLabel((int) $row->lpo_status_code),
                        'total_amount' => (float) ($row->total_amount ?? 0),
                        'path' => '/lpo/'.$row->lpo_no,
                    ])->all(),
                    'screens' => [
                        ['label' => 'Purchase orders (LPO)', 'path' => '/lpo'],
                    ],
                ];
            }
        }

        if (! $lpo) {
            return [
                'error' => true,
                'message' => 'No purchase order matched. Provide an LPO number, say “last LPO”, or a reference.',
                'screens' => [
                    ['label' => 'Purchase orders (LPO)', 'path' => '/lpo'],
                ],
            ];
        }

        return $this->presentLpo($lpo, $orgId, $user, isLatest: false);
    }

    protected function queryMeansLatest(string $query): bool
    {
        if ($query === '') {
            return false;
        }

        return (bool) preg_match(
            '/\b(last|latest|most\s+recent|newest|recent(?:ly)?\s+created|just\s+created|last\s+one)\b/i',
            $query,
        );
    }

    protected function latestLpoForOrganization(int $orgId): ?LpoMst
    {
        return LpoMst::query()
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->orderByDesc('lpo_no')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentLpo(LpoMst $lpo, int $orgId, User $user, bool $isLatest = false): array
    {
        $summary = $this->lpoModule->summary((int) $lpo->lpo_no, $orgId, $user);
        $header = is_array($summary['lpo'] ?? null) ? $summary['lpo'] : [];
        $lines = is_array($summary['lines'] ?? null) ? $summary['lines'] : [];

        $compactLines = array_map(static function (array $line) {
            return [
                'product_name' => $line['product_name'] ?? $line['product_code'] ?? null,
                'ordered_qty' => $line['ordered_qty'] ?? 0,
                'received_qty' => $line['received_qty'] ?? 0,
                'remaining_qty' => $line['remaining_qty'] ?? 0,
                'cost_price' => $line['cost_price'] ?? 0,
                'receive_status' => $line['receive_status'] ?? null,
                'lpo_txn_id' => $line['id'] ?? null,
            ];
        }, $lines);

        $statusCode = (int) ($header['lpo_status_code'] ?? 0);
        $nextSteps = $this->nextSteps($header);

        $documentLinks = LpoAiDocumentLinks::forLpo((int) $lpo->lpo_no);
        if (empty($header['can_receive'])) {
            $documentLinks = array_values(array_filter(
                $documentLinks,
                static fn (array $link) => ($link['kind'] ?? '') !== 'receive',
            ));
        }

        return [
            'lpo_no' => (int) $lpo->lpo_no,
            'po_number' => $header['po_number'] ?? null,
            'supplier_name' => $header['supplier_name'] ?? null,
            'status_code' => $statusCode,
            'status_name' => $header['status_name'] ?? LpoModuleService::statusLabel($statusCode),
            'reference_number' => $header['reference_number'] ?? null,
            'due_date' => $header['due_date'] ?? null,
            'total_amount' => $header['net_amount'] ?? $header['total_amount'] ?? 0,
            'approval_pending' => (bool) ($header['approval_pending'] ?? false),
            'workflow_actions' => $header['workflow_actions'] ?? [],
            'can_receive' => (bool) ($header['can_receive'] ?? false),
            'is_latest' => $isLatest,
            'lines' => $compactLines,
            'next_steps' => $nextSteps,
            'document_links' => $documentLinks,
            'path' => '/lpo/'.$lpo->lpo_no,
            'screens' => [
                ['label' => 'Open this LPO', 'path' => '/lpo/'.$lpo->lpo_no],
                ['label' => 'All purchase orders', 'path' => '/lpo'],
            ],
            'tip' => ($isLatest ? 'This is the most recently created LPO. ' : '')
                .'Keep the reply short: PO number, supplier, status, total. '
                .'Document buttons (Open / Print / PDF) already appear in the chat panel — do not restate them. '
                .'Only mention the single next workflow action that matches workflow_actions / can_receive.',
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     * @return list<string>
     */
    protected function nextSteps(array $header): array
    {
        $status = (int) ($header['lpo_status_code'] ?? 0);
        $actions = is_array($header['workflow_actions'] ?? null) ? $header['workflow_actions'] : [];
        $steps = [];

        if (in_array('submit_for_approval', $actions, true)) {
            $steps[] = 'Submit for approval.';
        } elseif (in_array('approve', $actions, true)) {
            $steps[] = 'Approve this LPO.';
        } elseif (in_array('mark_sent', $actions, true)) {
            $steps[] = 'Mark as sent to the supplier.';
        } elseif (
            ! empty($header['can_receive'])
            && $status >= LpoModuleService::STATUS_AWAITING_RECEIVE
            && $status < LpoModuleService::STATUS_FULLY_RECEIVED
        ) {
            $steps[] = 'Receive remaining goods into stock.';
        }

        return $steps;
    }
}
