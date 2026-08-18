<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerReturn;
use App\Models\Organization;
use App\Models\Sale;
use App\Services\Auth\UserAccessService;
use App\Services\OrganizationPlatformConfigService;
use App\Services\Sales\CustomerReturnService;
use App\Services\Sales\MobileRouteExpenseService;
use App\Services\Sales\SalePaymentStatusConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MobileOrdersQuickActionsController extends Controller
{
    public function __construct(
        protected CustomerReturnService $returns,
        protected SalePaymentStatusConversionService $paymentConversion,
        protected OrganizationPlatformConfigService $platformConfig,
        protected UserAccessService $access,
        protected MobileRouteExpenseService $routeExpenses,
    ) {}

    public function pendingReturns(Request $request)
    {
        $user = $request->user();
        $this->assertReturnsCardEnabled($this->organizationFor($user));
        $filters = $this->listFilters($request);

        $query = CustomerReturn::query()
            ->with(['lines.product', 'sale', 'customer', 'returnedByUser'])
            ->where('organization_id', $user->organization_id)
            ->where('status', 'pending');
        $this->constrainToMobileSale($query, $filters);

        if (Schema::hasColumn('customer_returns', 'return_kind')) {
            $query->where(function ($inner) {
                $inner->where('return_kind', 'standard')->orWhereNull('return_kind');
            });
        }

        $this->access->scopeBranchIfLimited($query, $user);

        $rows = $query->orderByDesc('id')->limit(200)->get()
            ->map(fn (CustomerReturn $return) => $this->returns->withActionFlags($return, $user));

        return response()->json(['data' => $rows]);
    }

    /**
     * Approved / completed mobile returns for the selected Mobile orders date window.
     */
    public function performedReturns(Request $request)
    {
        $user = $request->user();
        $this->assertReturnsCardEnabled($this->organizationFor($user));
        $filters = $this->listFilters($request);

        $query = CustomerReturn::query()
            ->with(['lines.product', 'sale', 'customer', 'returnedByUser', 'approvedByUser'])
            ->where('organization_id', $user->organization_id)
            ->where('status', 'approved');
        $this->constrainToMobileSale($query, $filters);

        if (Schema::hasColumn('customer_returns', 'return_kind')) {
            $query->where(function ($inner) {
                $inner->where('return_kind', 'standard')->orWhereNull('return_kind');
            });
        }

        $from = $filters['from_date'];
        $to = $filters['to_date'];
        if ($from || $to) {
            $query->where(function ($inner) use ($from, $to) {
                $dateCol = Schema::hasColumn('customer_returns', 'approved_at')
                    ? 'approved_at'
                    : 'created_at';
                if ($from) {
                    $inner->whereDate($dateCol, '>=', $from);
                }
                if ($to) {
                    $inner->whereDate($dateCol, '<=', $to);
                }
            });
        }

        $this->access->scopeBranchIfLimited($query, $user);

        $rows = $query->orderByDesc('id')->limit(200)->get()
            ->map(fn (CustomerReturn $return) => $this->returns->withActionFlags($return, $user));

        return response()->json(['data' => $rows]);
    }

    public function approveReturns(Request $request)
    {
        $user = $request->user();
        $this->assertReturnsCardEnabled($this->organizationFor($user));

        $data = $request->validate([
            'return_ids' => 'required|array|min:1',
            'return_ids.*' => 'integer',
            'cashier_id' => 'nullable|integer|min:1',
            'route_id' => 'nullable|integer|min:1',
        ]);
        $filters = [
            'cashier_id' => isset($data['cashier_id']) ? (int) $data['cashier_id'] : null,
            'route_id' => isset($data['route_id']) ? (int) $data['route_id'] : null,
        ];

        $approved = [];
        $errors = [];

        foreach ($data['return_ids'] as $returnId) {
            $query = CustomerReturn::query()
                ->where('id', (int) $returnId)
                ->where('organization_id', $user->organization_id)
                ->where('status', 'pending');
            $this->constrainToMobileSale($query, $filters);

            $this->access->scopeBranchIfLimited($query, $user);
            $return = $query->first();

            if (! $return) {
                $errors[] = ['id' => (int) $returnId, 'message' => 'Return not found or not pending.'];
                continue;
            }

            try {
                $approved[] = $this->returns->withActionFlags(
                    $this->returns->approve($return, $user),
                    $user,
                );
            } catch (ValidationException $e) {
                $errors[] = [
                    'id' => (int) $returnId,
                    'message' => collect($e->errors())->flatten()->first() ?: $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $errors[] = ['id' => (int) $returnId, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'approved_count' => count($approved),
            'data' => $approved,
            'errors' => $errors,
        ]);
    }

    public function markPaid(Request $request)
    {
        $user = $request->user();
        $this->assertPaymentsCardEnabled($this->organizationFor($user));

        $data = $request->validate([
            'sale_ids' => 'required|array|min:1',
            'sale_ids.*' => 'integer',
            'cashier_id' => 'nullable|integer|min:1',
            'route_id' => 'nullable|integer|min:1',
        ]);
        $cashierId = isset($data['cashier_id']) ? (int) $data['cashier_id'] : null;
        $routeId = isset($data['route_id']) ? (int) $data['route_id'] : null;

        $updated = [];
        $errors = [];

        foreach ($data['sale_ids'] as $saleId) {
            $query = Sale::query()
                ->where('id', (int) $saleId)
                ->where('organization_id', $user->organization_id)
                ->where('channel', 'mobile')
                ->where('status', '!=', 'cancelled');
            if ($cashierId) {
                $query->where('cashier_id', $cashierId);
            }
            if ($routeId) {
                $query->where('route_id', $routeId);
            }

            $this->access->scopeBranchIfLimited($query, $user);
            $sale = $query->first();

            if (! $sale) {
                $errors[] = ['id' => (int) $saleId, 'message' => 'Order not found.'];
                continue;
            }

            $total = round((float) $sale->order_total, 2);
            $alreadyPaid = round((float) $sale->amount_paid, 2);
            if ($alreadyPaid + 0.01 >= $total && $total > 0) {
                continue;
            }

            try {
                $updated[] = $this->paymentConversion->convertToPaid($sale, $user, true);
            } catch (ValidationException $e) {
                $errors[] = [
                    'id' => (int) $saleId,
                    'message' => collect($e->errors())->flatten()->first() ?: $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $errors[] = ['id' => (int) $saleId, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'updated_count' => count($updated),
            'data' => $updated,
            'errors' => $errors,
        ]);
    }

    public function pendingExpenses(Request $request)
    {
        $filters = $this->listFilters($request);

        return response()->json([
            'data' => $this->routeExpenses->pendingForManager($request->user(), $filters),
        ]);
    }

    public function performedExpenses(Request $request)
    {
        $filters = $this->listFilters($request);

        return response()->json([
            'data' => $this->routeExpenses->performedForManager($request->user(), $filters),
        ]);
    }

    public function approveExpenses(Request $request)
    {
        $data = $request->validate([
            'expense_ids' => 'required|array|min:1',
            'expense_ids.*' => 'integer',
            'cashier_id' => 'nullable|integer|min:1',
            'route_id' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            $this->routeExpenses->approveMany(
                $request->user(),
                $data['expense_ids'],
                [
                    'cashier_id' => isset($data['cashier_id']) ? (int) $data['cashier_id'] : null,
                    'route_id' => isset($data['route_id']) ? (int) $data['route_id'] : null,
                ],
            ),
        );
    }

    public function rejectExpenses(Request $request)
    {
        $data = $request->validate([
            'expense_ids' => 'required|array|min:1',
            'expense_ids.*' => 'integer',
            'reason' => 'nullable|string|max:200',
            'cashier_id' => 'nullable|integer|min:1',
            'route_id' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            $this->routeExpenses->rejectMany(
                $request->user(),
                $data['expense_ids'],
                $data['reason'] ?? null,
                [
                    'cashier_id' => isset($data['cashier_id']) ? (int) $data['cashier_id'] : null,
                    'route_id' => isset($data['route_id']) ? (int) $data['route_id'] : null,
                ],
            ),
        );
    }

    /**
     * Same User / Route / date filters as the Mobile orders list.
     *
     * @return array{from_date: ?string, to_date: ?string, cashier_id: ?int, route_id: ?int}
     */
    protected function listFilters(Request $request): array
    {
        $data = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'cashier_id' => 'nullable|integer|min:1',
            'route_id' => 'nullable|integer|min:1',
        ]);

        return [
            'from_date' => $data['from_date'] ?? null,
            'to_date' => $data['to_date'] ?? null,
            'cashier_id' => isset($data['cashier_id']) ? (int) $data['cashier_id'] : null,
            'route_id' => isset($data['route_id']) ? (int) $data['route_id'] : null,
        ];
    }

    /**
     * Restrict returns to mobile sales matching the list User / Route filters.
     *
     * @param  array{cashier_id?: int|null, route_id?: int|null}  $filters
     */
    protected function constrainToMobileSale($query, array $filters): void
    {
        $cashierId = (int) ($filters['cashier_id'] ?? 0);
        $routeId = (int) ($filters['route_id'] ?? 0);

        $query->whereHas('sale', function ($sale) use ($cashierId, $routeId) {
            $sale->where('channel', 'mobile');
            if ($cashierId > 0) {
                $sale->where('cashier_id', $cashierId);
            }
            if ($routeId > 0) {
                $sale->where('route_id', $routeId);
            }
        });
    }

    protected function organizationFor($user): Organization
    {
        $org = $user->organization ?? Organization::query()->find($user->organization_id);
        if (! $org) {
            throw ValidationException::withMessages([
                'organization' => ['Organization not found.'],
            ]);
        }

        return $org;
    }

    protected function assertReturnsCardEnabled(Organization $organization): void
    {
        $config = $this->platformConfig->salesPlatformConfigForOrganization($organization);
        if (! ($config['enable_mobile_orders'] ?? true) || ! ($config['enable_mobile_orders_returns_card'] ?? false)) {
            throw ValidationException::withMessages([
                'feature' => ['Mobile orders returns card is not enabled for this organization.'],
            ]);
        }
    }

    protected function assertPaymentsCardEnabled(Organization $organization): void
    {
        $config = $this->platformConfig->salesPlatformConfigForOrganization($organization);
        if (! ($config['enable_mobile_orders'] ?? true) || ! ($config['enable_mobile_orders_payments_card'] ?? false)) {
            throw ValidationException::withMessages([
                'feature' => ['Mobile orders payments card is not enabled for this organization.'],
            ]);
        }
    }
}
