<?php

namespace App\Services\Ai\Tools;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiSystemContextBuilder;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Lightweight purchasing overview: supplier count + recent open LPOs, plus screen links.
 */
class GetPurchasingOverviewTool implements AiToolInterface
{
    public function __construct(
        protected AiSystemContextBuilder $contextBuilder,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_purchasing_overview';
    }

    public function description(): string
    {
        return 'Get a Centrix purchasing overview: supplier count and recent purchase orders (LPO), '
            .'plus where to manage suppliers, LPOs, and supplier payments. '
            .'Use for supplier / LPO / purchasing questions when the user needs guidance or a snapshot.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max recent LPOs to return (1–20). Default 10.',
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

        $orgId = (int) $organization->id;
        if ((int) $user->organization_id !== $orgId && ! $user->is_super_admin) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'purchasing.suppliers.view', $gate)
            || $this->permissions->hasPermission($user, 'purchasing.lpo.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view purchasing data.',
                'screens' => [
                    ['label' => 'Suppliers', 'path' => '/suppliers'],
                    ['label' => 'Purchase orders (LPO)', 'path' => '/lpo'],
                ],
            ];
        }

        $limit = max(1, min(20, (int) ($arguments['limit'] ?? 10)));
        $supplierCount = 0;
        $recentLpos = [];

        if (Schema::hasTable('suppliers')) {
            $supplierCount = (int) DB::table('suppliers')
                ->where('organization_id', $orgId)
                ->count();
        }

        $lpoTable = Schema::hasTable('lpo_mst')
            ? 'lpo_mst'
            : (Schema::hasTable('purchase_orders') ? 'purchase_orders' : null);

        if ($lpoTable) {
            $query = DB::table($lpoTable)->where('organization_id', $orgId);
            $columns = Schema::getColumnListing($lpoTable);
            $orderCol = in_array('created_at', $columns, true) ? 'created_at' : (in_array('lpo_no', $columns, true) ? 'lpo_no' : 'id');
            $select = array_values(array_unique(array_filter([
                in_array('lpo_no', $columns, true) ? 'lpo_no' : null,
                in_array('id', $columns, true) ? 'id' : null,
                in_array('reference_number', $columns, true) ? 'reference_number' : null,
                in_array('lpo_status_code', $columns, true) ? 'lpo_status_code' : (in_array('status', $columns, true) ? 'status' : null),
                in_array('supplier_id', $columns, true) ? 'supplier_id' : null,
                in_array('total_amount', $columns, true) ? 'total_amount' : null,
                $orderCol,
            ])));
            $recentLpos = $query->orderByDesc($orderCol)
                ->limit($limit)
                ->get($select ?: ['*'])
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        return [
            'organization_id' => $orgId,
            'supplier_count' => $supplierCount,
            'recent_purchase_orders' => $recentLpos,
            'screens' => [
                ['label' => 'Suppliers', 'path' => '/suppliers'],
                ['label' => 'Purchase orders (LPO)', 'path' => '/lpo'],
                ['label' => 'Supplier payments', 'path' => '/suppliers/payments'],
                ['label' => 'Stock receipts (GRN)', 'path' => '/inventory/receipts'],
            ],
            'note' => 'Open the screens above for full supplier balances, LPO details, and GRN receiving. '
                .'This overview is a snapshot, not a full AP aging report.',
        ];
    }

    protected function resolveOrganizationForUser(User $user): ?Organization
    {
        $request = request();
        $actingId = $request->attributes->get('acting_organization_id');
        if ($actingId && ($request->user()?->id === $user->id || $user->is_super_admin)) {
            $acting = Organization::query()->find((int) $actingId);
            if ($acting) {
                return $acting;
            }
        }

        return Organization::query()->find((int) $user->organization_id);
    }
}
