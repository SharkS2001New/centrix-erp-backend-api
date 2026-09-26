<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GetTillHealthTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_till_health';
    }

    public function description(): string
    {
        return 'Get Centrix till/float status. For a named cashier (e.g. "Diana\'s float today"), pass cashier_name '
            .'and relative_date=today — returns opening float, cash/M-Pesa/bank tenders, and expected cash. '
            .'Without cashier_name, returns org till variance outliers and payment mix for the lookback.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lookback_days' => [
                    'type' => 'integer',
                    'description' => 'Days of till history when not filtering one cashier (1–90). Default 14.',
                ],
                'relative_date' => [
                    'type' => 'string',
                    'enum' => ['today', 'yesterday', 'last_7_days'],
                    'description' => 'Prefer for "today" / "yesterday" cashier float questions.',
                ],
                'date' => [
                    'type' => 'string',
                    'description' => 'Single day YYYY-MM-DD when relative_date is omitted.',
                ],
                'cashier_name' => [
                    'type' => 'string',
                    'description' => 'Cashier full name or username (e.g. Diana).',
                ],
                'username' => [
                    'type' => 'string',
                    'description' => 'Login username of the cashier.',
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

        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.pos.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view till data.',
            ];
        }

        $cashierName = trim((string) ($arguments['cashier_name'] ?? $arguments['username'] ?? ''));
        if ($cashierName !== '') {
            [$from, $to] = AiSalesDateResolver::resolve(
                array_merge($arguments, [
                    'relative_date' => $arguments['relative_date'] ?? 'today',
                ]),
                $organization,
            );

            return $this->cashierFloatForPeriod($organization, $cashierName, $from, $to);
        }

        $lookback = (int) ($arguments['lookback_days'] ?? 14);
        $slice = $this->insightData->cashTillHealthSlice($organization, $user, $lookback);

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'POS', 'path' => '/sales/pos'],
                ['label' => 'Till management', 'path' => '/sales/till-management'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function cashierFloatForPeriod($organization, string $cashierName, string $from, string $to): array
    {
        $orgId = (int) $organization->id;
        $needle = mb_strtolower($cashierName);

        $users = User::query()
            ->where('organization_id', $orgId)
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(full_name) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(username) LIKE ?', ['%'.$needle.'%']);
            })
            ->limit(8)
            ->get(['id', 'full_name', 'username']);

        if ($users->isEmpty()) {
            return [
                'type' => 'cashier_till_float',
                'error' => true,
                'searched_for' => $cashierName,
                'message' => 'No cashier matched that name.',
                'currency' => 'KES',
            ];
        }

        if ($users->count() > 1) {
            $exact = $users->first(function ($u) use ($needle) {
                return mb_strtolower((string) $u->username) === $needle
                    || mb_strtolower((string) $u->full_name) === $needle;
            });
            if (! $exact) {
                return [
                    'type' => 'cashier_till_float',
                    'error' => true,
                    'searched_for' => $cashierName,
                    'candidates' => $users->map(fn ($u) => [
                        'full_name' => $u->full_name,
                        'username' => $u->username,
                    ])->values()->all(),
                    'message' => 'Several cashiers matched — ask the user to pick one.',
                    'currency' => 'KES',
                ];
            }
            $cashier = $exact;
        } else {
            $cashier = $users->first();
        }

        $cashierId = (int) $cashier->id;
        $session = null;
        if (Schema::hasTable('till_float_sessions')) {
            $session = DB::table('till_float_sessions as t')
                ->leftJoin('tills as ti', 'ti.id', '=', 't.till_id')
                ->where('t.organization_id', $orgId)
                ->where('t.cashier_id', $cashierId)
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween(DB::raw('DATE(t.opened_at)'), [$from, $to])
                        ->orWhereBetween('t.session_date', [$from, $to])
                        ->orWhereIn('t.status', ['open', 'active']);
                })
                ->orderByDesc('t.id')
                ->first([
                    't.id',
                    't.status',
                    't.working_amount',
                    't.expected_amount',
                    't.cash_sales',
                    't.opened_at',
                    't.session_date',
                    'ti.till_name',
                ]);
        }

        $mix = DB::table('sales')
            ->where('organization_id', $orgId)
            ->where('cashier_id', $cashierId)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw('
                ROUND(SUM(COALESCE(cash, 0)), 2) as cash_total,
                ROUND(SUM(COALESCE(mpesa_amount, 0)), 2) as mpesa_total,
                ROUND(SUM(COALESCE(equity_amount, 0) + COALESCE(kcb_amount, 0)), 2) as bank_total,
                ROUND(SUM(COALESCE(amount_paid, 0)), 2) as collected_total,
                ROUND(SUM(order_total), 2) as order_total,
                COUNT(*) as order_count
            ')
            ->first();

        $openingFloat = round((float) ($session->working_amount ?? 0), 2);
        $cash = round((float) ($mix->cash_total ?? 0), 2);
        $mpesa = round((float) ($mix->mpesa_total ?? 0), 2);
        $bank = round((float) ($mix->bank_total ?? 0), 2);
        $collected = round((float) ($mix->collected_total ?? 0), 2);
        $expected = $session && $session->expected_amount !== null
            ? round((float) $session->expected_amount, 2)
            : round($openingFloat + $cash, 2);

        return [
            'type' => 'cashier_till_float',
            'organization_id' => $orgId,
            'currency' => 'KES',
            'from_date' => $from,
            'to_date' => $to,
            'cashier' => [
                'full_name' => $cashier->full_name,
                'username' => $cashier->username,
            ],
            'matched_username' => $cashier->username,
            'session' => $session ? [
                'status' => $session->status,
                'till' => $session->till_name ?: null,
                'opened_at' => $session->opened_at,
                'opening_float' => $openingFloat,
                'expected_cash' => $expected,
                'cash_sales_on_session' => round((float) ($session->cash_sales ?? 0), 2),
            ] : null,
            'opening_float' => $openingFloat,
            'tenders' => [
                'cash' => $cash,
                'mpesa' => $mpesa,
                'bank' => $bank,
                'collected_total' => $collected,
            ],
            'order_total' => round((float) ($mix->order_total ?? 0), 2),
            'order_count' => (int) ($mix->order_count ?? 0),
            'expected_cash_in_drawer' => $expected,
            'direct_answer' => sprintf(
                '%s: opening float KES %s. Cash KES %s, M-Pesa KES %s, bank KES %s. Expected cash in drawer about KES %s.',
                trim((string) ($cashier->full_name ?: $cashier->username)),
                number_format($openingFloat, 2, '.', ','),
                number_format($cash, 2, '.', ','),
                number_format($mpesa, 2, '.', ','),
                number_format($bank, 2, '.', ','),
                number_format($expected, 2, '.', ','),
            ),
            'screens' => [
                ['label' => 'Till management', 'path' => '/sales/till-management'],
                ['label' => 'Sales by user', 'path' => '/reports/sales-by-user'],
            ],
        ];
    }
}
