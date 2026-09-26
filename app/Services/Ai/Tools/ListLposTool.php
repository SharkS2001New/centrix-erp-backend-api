<?php

namespace App\Services\Ai\Tools;

use App\Models\LpoMst;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\LpoModuleService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * List purchase orders filtered by status (e.g. awaiting goods receiving).
 */
class ListLposTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected LpoModuleService $lpoModule,
    ) {}

    public function name(): string
    {
        return 'list_lpos';
    }

    public function description(): string
    {
        return 'List Centrix purchase orders (LPOs) filtered by status or semantic filter. '
            .'Use filter=awaiting_receive (or open_for_receive) for LPOs waiting on goods receiving '
            .'(status Awaiting receive + Partially received only — NOT fully received, cleared, or awaiting check/approval). '
            .'Also supports status_code, status_codes, supplier_name, and limit. '
            .'Use when the user asks which LPOs await receive / GRN / goods receiving, open POs by status, or lists LPOs.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'description' => 'Semantic filter. Prefer awaiting_receive for goods-receiving questions. '
                        .'Values: awaiting_receive | open_for_receive | awaiting_check | awaiting_approval | '
                        .'awaiting_send | partially_received | fully_received | cleared | open_workflow | all.',
                ],
                'status_code' => [
                    'type' => 'integer',
                    'description' => 'Exact lpo_status_code (0–7). Prefer filter=awaiting_receive for receive questions.',
                ],
                'status_codes' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Multiple status codes when needed.',
                ],
                'supplier_id' => [
                    'type' => 'integer',
                    'description' => 'Limit to one supplier id.',
                ],
                'supplier_name' => [
                    'type' => 'string',
                    'description' => 'Supplier name search when id is unknown.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows (1–50). Default 25.',
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

        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 25)));
        $filter = strtolower(trim((string) ($arguments['filter'] ?? '')));
        $statusCodes = $this->resolveStatusCodes($arguments, $filter);

        $query = LpoMst::query()
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at');

        if ($statusCodes !== null) {
            $query->whereIn('lpo_status_code', $statusCodes);
        }

        $supplierId = (int) ($arguments['supplier_id'] ?? 0);
        $supplierName = trim((string) ($arguments['supplier_name'] ?? ''));
        if ($supplierId <= 0 && $supplierName !== '') {
            $supplierId = (int) (Supplier::query()
                ->where('organization_id', $orgId)
                ->where('supplier_name', 'like', '%'.$supplierName.'%')
                ->orderBy('supplier_name')
                ->value('id') ?? 0);
        }
        if ($supplierId > 0) {
            $query->where('supplier_id', $supplierId);
        }

        $totalMatching = (clone $query)->count();
        $rows = $query->orderByDesc('lpo_no')->limit($limit)->get();
        $mapped = $this->lpoModule->mapListRows($rows, $orgId, $user);

        $items = array_map(static function (array $row) {
            $statusCode = (int) ($row['lpo_status_code'] ?? 0);
            $openForReceive = in_array($statusCode, [
                LpoModuleService::STATUS_AWAITING_RECEIVE,
                LpoModuleService::STATUS_PARTIALLY_RECEIVED,
            ], true);

            return [
                'lpo_no' => (int) ($row['lpo_no'] ?? 0),
                'po_number' => $row['po_number'] ?? null,
                'supplier_name' => $row['supplier_name'] ?? null,
                'status_code' => $statusCode,
                'status_name' => $row['status_name'] ?? LpoModuleService::statusLabel($statusCode),
                'total_amount' => (float) ($row['net_amount'] ?? $row['total_amount'] ?? 0),
                'due_date' => $row['due_date'] ?? null,
                'open_for_receive' => $openForReceive,
                'path' => '/lpo/'.(int) ($row['lpo_no'] ?? 0),
                'receive_path' => $openForReceive
                    ? '/lpo/'.(int) ($row['lpo_no'] ?? 0).'/receive'
                    : null,
            ];
        }, $mapped);

        $filterLabel = $this->filterLabel($filter, $statusCodes);
        $listPath = '/lpo';
        if ($statusCodes !== null && count($statusCodes) === 1) {
            $listPath = '/lpo?status_code='.$statusCodes[0];
        } elseif ($filter === 'awaiting_receive' || $filter === 'open_for_receive') {
            $listPath = '/lpo?status_code='.LpoModuleService::STATUS_AWAITING_RECEIVE;
        }

        return [
            'filter' => $filter !== '' ? $filter : ($statusCodes === null ? 'all' : 'status_codes'),
            'filter_label' => $filterLabel,
            'status_codes' => $statusCodes,
            'count' => count($items),
            'total_matching' => $totalMatching,
            'purchase_orders' => $items,
            'note' => $filter === 'awaiting_receive' || $filter === 'open_for_receive'
                ? 'Only LPOs with status Awaiting receive or Partially received. Fully received, cleared, and pre-send statuses are excluded.'
                : null,
            'screens' => [
                ['label' => 'Filtered purchase orders', 'path' => $listPath],
                ['label' => 'All purchase orders', 'path' => '/lpo'],
                ['label' => 'Stock receipts (GRN)', 'path' => '/inventory/receipts'],
            ],
            'tip' => 'Answer with a short table (PO | Supplier | Status | Total). Do not list fully received/cleared LPOs when filter is awaiting_receive. '
                .'Document buttons in the chat panel are enough — do not repeat Open/Print/PDF for every row.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<int>|null  null = no status filter
     */
    protected function resolveStatusCodes(array $arguments, string $filter): ?array
    {
        if (isset($arguments['status_codes']) && is_array($arguments['status_codes']) && $arguments['status_codes'] !== []) {
            return array_values(array_unique(array_map('intval', $arguments['status_codes'])));
        }

        if (isset($arguments['status_code']) && $arguments['status_code'] !== '' && $arguments['status_code'] !== null) {
            return [(int) $arguments['status_code']];
        }

        return match ($filter) {
            'awaiting_receive', 'open_for_receive', 'awaiting_goods', 'goods_receiving', 'to_receive' => [
                LpoModuleService::STATUS_AWAITING_RECEIVE,
                LpoModuleService::STATUS_PARTIALLY_RECEIVED,
            ],
            'awaiting_check' => [0],
            'awaiting_approval' => [1],
            'awaiting_send' => [2],
            'partially_received' => [LpoModuleService::STATUS_PARTIALLY_RECEIVED],
            'fully_received' => [LpoModuleService::STATUS_FULLY_RECEIVED],
            'cleared' => [LpoModuleService::STATUS_CLEARED],
            'open_workflow' => [0, 1, 2, 3, 4],
            'all', '' => null,
            default => null,
        };
    }

    /**
     * @param  list<int>|null  $statusCodes
     */
    protected function filterLabel(string $filter, ?array $statusCodes): string
    {
        if (in_array($filter, ['awaiting_receive', 'open_for_receive', 'awaiting_goods', 'goods_receiving', 'to_receive'], true)) {
            return 'Awaiting goods receiving (including partially received)';
        }

        if ($statusCodes === null) {
            return 'All purchase orders';
        }

        if (count($statusCodes) === 1) {
            return LpoModuleService::statusLabel($statusCodes[0]);
        }

        return implode(', ', array_map(
            static fn (int $code) => LpoModuleService::statusLabel($code),
            $statusCodes,
        ));
    }
}
