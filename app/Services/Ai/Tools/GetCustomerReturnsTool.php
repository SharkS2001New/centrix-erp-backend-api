<?php

namespace App\Services\Ai\Tools;

use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\HasAiPeriodParameters;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Ai\Tools\Concerns\ResolvesAiUsers;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Customer / mobile product returns for AI chat.
 */
class GetCustomerReturnsTool implements AiToolInterface
{
    use HasAiPeriodParameters;
    use ResolvesAiToolOrganization;
    use ResolvesAiUsers;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_customer_returns';
    }

    public function description(): string
    {
        return 'Get Centrix customer/product returns (customer_returns) for a period: return numbers, amounts, '
            .'status, and who returned them (returned_by). Centrix DOES attribute returns to users — '
            .'NEVER say returns cannot be shown per salesperson. '
            .'For "returns done by CHEGE/Jane": ALWAYS pass user_name or username with relative_date. '
            .'Never invent return totals — always call this tool.';
    }

    public function parametersSchema(): array
    {
        $schema = $this->aiPeriodParametersSchema();
        $schema['properties']['user_name'] = [
            'type' => 'string',
            'description' => 'Filter returns submitted by this user (full name or username).',
        ];
        $schema['properties']['username'] = [
            'type' => 'string',
            'description' => 'Login username of the person who submitted the return.',
        ];
        $schema['properties']['status'] = [
            'type' => 'string',
            'description' => 'Optional status filter (pending, approved, rejected).',
        ];
        $schema['properties']['limit'] = [
            'type' => 'integer',
            'description' => 'Max returns to list (default 40, max 100).',
        ];

        return $schema;
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

        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.returns.view', $gate)
            || $this->permissions->hasPermission($user, 'customers.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view returns.',
                'screens' => $this->screens(),
            ];
        }

        if (! Schema::hasTable('customer_returns')) {
            return [
                'error' => true,
                'message' => 'Customer returns are not available in this Centrix installation.',
                'screens' => $this->screens(),
            ];
        }

        $orgId = (int) $organization->id;
        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);

        $returnedById = null;
        $returnedByLabel = null;
        $name = trim((string) ($arguments['user_name'] ?? $arguments['username'] ?? ''));
        if ($name !== '') {
            $resolved = $this->resolveUserByName($orgId, $name, false);
            if (($resolved['error'] ?? false) === true) {
                return array_merge($resolved, ['screens' => $this->screens()]);
            }
            /** @var User $rep */
            $rep = $resolved['user'];
            $returnedById = (int) $rep->id;
            $returnedByLabel = trim((string) ($rep->full_name ?: $rep->username));
        }

        $status = strtolower(trim((string) ($arguments['status'] ?? '')));
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 40)));

        $query = CustomerReturn::query()
            ->where('organization_id', $orgId)
            ->with(['returnedByUser:id,username,full_name'])
            ->whereDate('return_date', '>=', $from)
            ->whereDate('return_date', '<=', $to)
            ->orderByDesc('return_date')
            ->orderByDesc('id');

        if ($returnedById !== null) {
            $query->where('returned_by', $returnedById);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $rows = $query->limit($limit)->get();
        $returns = [];
        $totalAmount = 0.0;
        $byStatus = [];

        foreach ($rows as $row) {
            $amount = round((float) ($row->total_amount ?? 0), 2);
            $totalAmount += $amount;
            $st = (string) ($row->status ?? 'unknown');
            $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
            $who = $row->returnedByUser;
            $whoName = $who
                ? trim((string) ($who->full_name ?: $who->username))
                : null;

            $returns[] = [
                'return_no' => $row->return_no,
                'return_date' => $row->return_date?->toDateString(),
                'status' => $st,
                'total_amount' => $amount,
                'reason' => $row->reason,
                'customer_num' => $row->customer_num,
                'returned_by_name' => $whoName !== '' ? $whoName : null,
                'return_kind' => $row->return_kind,
            ];
        }

        return [
            'currency' => 'KES',
            'period' => [
                'from_date' => $from,
                'to_date' => $to,
            ],
            'filtered_user' => $returnedByLabel,
            'summary' => [
                'returns_count' => count($returns),
                'total_amount' => round($totalAmount, 2),
                'by_status' => $byStatus,
            ],
            'returns' => $returns,
            'screens' => $this->screens(),
            'tip' => ($returnedByLabel
                ? "These are returns submitted by {$returnedByLabel} (customer_returns.returned_by). "
                    .'Never say Centrix cannot attribute returns to a user. '
                : 'If the user named a person, call again with user_name — returns are attributed via returned_by. ')
                .'Show a markdown table (date, return no, status, amount, returned by). '
                .'Use display names only — never numeric user ids.',
        ];
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    protected function screens(): array
    {
        return [
            ['label' => 'Customer returns', 'path' => '/sales/returns'],
            ['label' => 'Mobile orders', 'path' => '/sales/orders/queues/mobile'],
            ['label' => 'Sales orders', 'path' => '/sales/orders'],
        ];
    }
}
