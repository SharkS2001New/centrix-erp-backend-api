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
        return 'Look up a Centrix purchase order (LPO) by number or reference and return status, supplier, '
            .'line items, remaining qty, available workflow actions (submit for approval, approve, mark sent, receive), '
            .'and document links (open, print, download PDF). '
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
                    'description' => 'PO number, reference, or partial search when lpo_no is unknown.',
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
                'message' => 'No purchase order matched. Provide an LPO number (e.g. 123) or reference.',
                'screens' => [
                    ['label' => 'Purchase orders (LPO)', 'path' => '/lpo'],
                ],
            ];
        }

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
        // Hide receive shortcut until the LPO can be received.
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
            'lines' => $compactLines,
            'next_steps' => $nextSteps,
            'document_links' => $documentLinks,
            'path' => '/lpo/'.$lpo->lpo_no,
            'screens' => [
                ['label' => 'Open this LPO', 'path' => '/lpo/'.$lpo->lpo_no],
                ['label' => 'Print LPO', 'path' => '/lpo/'.$lpo->lpo_no.'/print'],
                ['label' => 'All purchase orders', 'path' => '/lpo'],
            ],
            'tip' => 'Offer Download PDF / Print links from document_links. For mutations use confirm actions: '
                .'submit_lpo_for_approval, approve_lpo, mark_lpo_sent, or receive_lpo_goods.',
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

        if (in_array('submit_for_approval', $actions, true) || $status === 0) {
            $steps[] = 'Submit for approval (chat: “submit LPO for approval”).';
        }
        if (in_array('approve', $actions, true) || $status === 1) {
            $steps[] = 'Approve the LPO (chat: “approve LPO”).';
        }
        if (in_array('mark_sent', $actions, true) || $status === 2) {
            $steps[] = 'Mark as sent to supplier, then print/download PDF to share.';
        }
        if (! empty($header['can_receive'])) {
            $steps[] = 'Receive goods into stock (chat: “receive LPO” or open Receive).';
        }
        if ($steps === []) {
            $steps[] = 'Open the LPO screen for payments, returns, or clearance.';
        }

        return $steps;
    }
}
