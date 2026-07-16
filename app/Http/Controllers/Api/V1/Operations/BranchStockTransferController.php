<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\BranchStockTransferRequest;
use App\Models\Branch;
use App\Models\BranchStockTransfer;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Audit\OperationalAuditService;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\InAppNotificationEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BranchStockTransferController extends Controller
{
    use HandlesInventory;

    public function index(Request $request)
    {
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $access = app(UserAccessService::class);

        $query = BranchStockTransfer::query()
            ->with(['fromBranch:id,branch_name,branch_code', 'toBranch:id,branch_name,branch_code', 'product:product_code,product_name'])
            ->where('organization_id', $orgId)
            ->orderByDesc('id');

        $limitedBranch = $access->branchId($user);
        if ($limitedBranch !== null) {
            $query->where(function ($q) use ($limitedBranch) {
                $q->where('from_branch_id', $limitedBranch)->orWhere('to_branch_id', $limitedBranch);
            });
        } else {
            $branchId = $request->input('filter.branch_id', $request->input('branch_id'));
            if ($branchId !== null && $branchId !== '') {
                $branchId = (int) $branchId;
                $access->assertBranchInOrganization($user, $branchId, $request);
                $query->where(function ($q) use ($branchId) {
                    $q->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId);
                });
            }
            if ($from = $request->input('filter.from_branch_id', $request->input('from_branch_id'))) {
                $access->assertBranchInOrganization($user, (int) $from, $request);
                $query->where('from_branch_id', (int) $from);
            }
            if ($to = $request->input('filter.to_branch_id', $request->input('to_branch_id'))) {
                $access->assertBranchInOrganization($user, (int) $to, $request);
                $query->where('to_branch_id', (int) $to);
            }
        }

        return response()->json([
            'data' => $query->limit(200)->get(),
        ]);
    }

    public function store(BranchStockTransferRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();
        $result = $this->transferBetweenBranches($data, $user);
        app(OperationalAuditService::class)->logStockMovement($user, 'branch_transfer', [
            'transfer_id' => (int) $result->id,
            'product_code' => (string) $result->product_code,
            'from_branch_id' => (int) $result->from_branch_id,
            'to_branch_id' => (int) $result->to_branch_id,
            'from_location' => (string) $result->from_location,
            'to_location' => (string) $result->to_location,
            'quantity' => (float) $result->quantity,
        ]);
        app(AdminNotificationService::class)->notifyPermission($user, 'inventory.manage', [
            'type' => 'info',
            'severity' => 'warning',
            'title' => 'Branch stock transfer posted',
            'message' => ($user->full_name ?: $user->username)." transferred {$result->quantity} {$result->product_code} between branches.",
            'action_url' => '/inventory/transfers',
        ], InAppNotificationEvents::STOCK_TRANSFER);

        return response()->json($result, 201);
    }

    /** @param  array<string, mixed>  $data */
    protected function transferBetweenBranches(array $data, User $user): BranchStockTransfer
    {
        $fromBranchId = (int) $data['from_branch_id'];
        $toBranchId = (int) $data['to_branch_id'];
        $productCode = (string) $data['product_code'];
        $quantity = (float) $data['quantity'];
        $fromLocation = (string) $data['from_location'];
        $toLocation = (string) $data['to_location'];
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : null;

        $fromBranch = Branch::query()->findOrFail($fromBranchId);
        $toBranch = Branch::query()->findOrFail($toBranchId);

        if ((int) $fromBranch->organization_id !== (int) $user->organization_id
            || (int) $toBranch->organization_id !== (int) $user->organization_id) {
            throw new InvalidArgumentException('Both branches must belong to your organization.');
        }

        app(UserAccessService::class)->assertBranchAccess($user, $fromBranchId, 'You do not have access to the source branch.');
        app(UserAccessService::class)->assertBranchAccess($user, $toBranchId, 'You do not have access to the destination branch.');

        $allowBelowStock = $this->organizationAllowsBelowStock((int) $user->organization_id);
        $unitCost = app(\App\Services\Inventory\StockValuationService::class)
            ->effectiveUnitCostForProduct((int) $user->organization_id, $productCode);

        return DB::transaction(function () use (
            $user,
            $fromBranchId,
            $toBranchId,
            $productCode,
            $quantity,
            $fromLocation,
            $toLocation,
            $notes,
            $allowBelowStock,
            $unitCost,
        ) {
            $record = BranchStockTransfer::create([
                'organization_id' => (int) $user->organization_id,
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'product_code' => $productCode,
                'quantity' => $quantity,
                'from_location' => $fromLocation,
                'to_location' => $toLocation,
                'notes' => $notes,
                'created_by' => $user->id,
            ]);

            $transferNote = $notes ?: "Branch transfer #{$record->id}";

            $out = $this->postStockLedger([
                'branch_id' => $fromBranchId,
                'product_code' => $productCode,
                'stock_location' => $fromLocation,
                'transaction_type' => 'TRANSFER',
                'reference_type' => 'branch_transfer',
                'reference_id' => $record->id,
                'quantity_change' => -abs($quantity),
                'unit_cost' => $unitCost > 0 ? $unitCost : null,
                'created_by' => $user->id,
                'notes' => "Out to branch {$toBranchId}: {$transferNote}",
            ], $allowBelowStock);

            $this->postStockLedger([
                'branch_id' => $toBranchId,
                'product_code' => $productCode,
                'stock_location' => $toLocation,
                'transaction_type' => 'TRANSFER',
                'reference_type' => 'branch_transfer',
                'reference_id' => $record->id,
                'quantity_change' => abs($quantity),
                'unit_cost' => $unitCost > 0 ? $unitCost : null,
                'created_by' => $user->id,
                'notes' => "In from branch {$fromBranchId}: {$transferNote}",
            ], $allowBelowStock);

            return $record->fresh(['fromBranch', 'toBranch', 'product']);
        });
    }
}
