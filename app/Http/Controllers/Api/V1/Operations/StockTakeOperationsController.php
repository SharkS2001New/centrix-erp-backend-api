<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesBranchScope;
use App\Http\Controllers\Controller;
use App\Jobs\InitializeStockTakeSessionJob;
use App\Jobs\SaveStockTakeCountsJob;
use App\Models\CurrentStock;
use App\Models\Product;
use App\Models\StockTakeLine;
use App\Models\StockTakeSession;
use App\Models\User;
use App\Services\Accounting\StockTakeJournalService;
use App\Services\Background\BackgroundTaskService;
use App\Services\Catalog\ProductCatalogFilterService;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Erp\ErpContext;
use App\Services\Inventory\StockTakeApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockTakeOperationsController extends Controller
{
    use HandlesBranchScope;
    use HandlesInventory;

    private const SYNC_SAVE_LINE_LIMIT = 25;

    public function __construct(
        protected ErpContext $erp,
        protected ProductCatalogScopeService $catalogScope,
        protected BackgroundTaskService $tasks,
    ) {}

    public function initialize(Request $request, int $sessionId)
    {
        $session = $this->findScopedStockTakeSession($sessionId, $request->user());

        $existing = StockTakeLine::query()->where('session_id', $session->id)->count();
        if ($existing > 0) {
            return response()->json(
                $this->initializeStockTakeLines($session, $request)
            );
        }

        $task = $this->tasks->create('stock_take_initialize', $request->user(), [
            'session_id' => $session->id,
        ]);

        InitializeStockTakeSessionJob::dispatch($task->id);

        return response()->json([
            'message' => 'Stock take initialization queued.',
            'task_id' => $task->id,
        ], 202);
    }

    public function saveCounts(Request $request, int $sessionId)
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:5000'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.counted_quantity' => ['required', 'numeric'],
        ]);

        $session = $this->findScopedStockTakeSession($sessionId, $request->user());
        if ($session->status === 'completed') {
            throw new InvalidArgumentException('Session already completed.');
        }

        $lines = $data['lines'];
        if (count($lines) <= self::SYNC_SAVE_LINE_LIMIT) {
            return response()->json(
                $this->saveCountsSync($session, $lines)
            );
        }

        $task = $this->tasks->create('stock_take_save_counts', $request->user(), [
            'session_id' => $session->id,
            'lines' => $lines,
        ]);

        SaveStockTakeCountsJob::dispatch($task->id);

        return response()->json([
            'message' => 'Stock take counts save queued.',
            'task_id' => $task->id,
        ], 202);
    }

    /** @param  list<array{id: int, counted_quantity: float|int|string}>  $lines */
    public function saveCountsSync(StockTakeSession $session, array $lines): array
    {
        $allowedIds = StockTakeLine::query()
            ->where('session_id', $session->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $allowedMap = array_fill_keys($allowedIds, true);

        $updated = 0;
        foreach ($lines as $line) {
            $lineId = (int) ($line['id'] ?? 0);
            if ($lineId <= 0 || ! isset($allowedMap[$lineId])) {
                continue;
            }

            StockTakeLine::query()
                ->where('id', $lineId)
                ->update([
                    'counted_quantity' => (float) ($line['counted_quantity'] ?? 0),
                ]);
            $updated++;
        }

        return [
            'session_id' => $session->id,
            'updated' => $updated,
        ];
    }

    public function initializeStockTakeLines(StockTakeSession $session, Request $request): array
    {
        if ($session->status === 'completed') {
            throw new InvalidArgumentException('Session already completed.');
        }

        $existing = StockTakeLine::query()->where('session_id', $session->id)->count();
        if ($existing > 0) {
            return [
                'session' => $session,
                'lines_created' => 0,
                'already_initialized' => true,
            ];
        }

        $branchId = (int) $session->branch_id;
        $request->merge(['branch_id' => $branchId]);

        $productQuery = Product::query()->whereNull('deleted_at');
        $this->catalogScope->scopeForUser($productQuery, $request->user(), $request);
        ProductCatalogFilterService::applyTaxonomyFilters(
            $productQuery,
            $session->filter_category_id ? (int) $session->filter_category_id : null,
            $session->filter_subcategory_id ? (int) $session->filter_subcategory_id : null,
            $session->filter_supplier_id ? (int) $session->filter_supplier_id : null,
        );
        $productCodes = $productQuery->pluck('product_code');

        $stockByCode = CurrentStock::query()
            ->where('branch_id', $branchId)
            ->get()
            ->keyBy('product_code');

        $loc = (string) $session->stock_location;
        $rows = [];

        foreach ($productCodes as $code) {
            $stock = $stockByCode->get($code);
            $shopQty = (float) ($stock->shop_quantity ?? 0);
            $storeQty = (float) ($stock->store_quantity ?? 0);

            if ($loc === 'both' || $loc === 'shop') {
                $rows[] = [
                    'session_id' => $session->id,
                    'product_code' => $code,
                    'stock_location' => 'shop',
                    'system_quantity' => $shopQty,
                    'counted_quantity' => $shopQty,
                ];
            }
            if ($loc === 'both' || $loc === 'store') {
                $rows[] = [
                    'session_id' => $session->id,
                    'product_code' => $code,
                    'stock_location' => 'store',
                    'system_quantity' => $storeQty,
                    'counted_quantity' => $storeQty,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            StockTakeLine::insert($chunk);
        }

        return [
            'session' => $session->fresh(),
            'lines_created' => count($rows),
            'already_initialized' => false,
        ];
    }

    public function complete(Request $request, int $sessionId)
    {
        $session = $this->findScopedStockTakeSession($sessionId, $request->user());

        if (! app(StockTakeApprovalService::class)->canApprove($request->user())) {
            $actionRequest = app(StockTakeApprovalService::class)->requestCompletion($request->user(), $session);

            return response()->json([
                'message' => 'Stock take completion submitted for admin approval.',
                'pending_approval' => true,
                'action_request_id' => (int) $actionRequest->id,
            ], 202);
        }

        return response()->json(
            $this->completeStockTakeSession($session, $request->user())
        );
    }

    public function completeStockTakeSession(StockTakeSession $session, User $user): StockTakeSession
    {
        if ($session->status === 'completed') {
            throw new InvalidArgumentException('Session already completed.');
        }

        return DB::transaction(function () use ($session, $user) {
            $lines = StockTakeLine::where('session_id', $session->id)->lockForUpdate()->get();
            $orgId = (int) ($user->organization_id
                ?? DB::table('branches')->where('id', $session->branch_id)->value('organization_id'));

            foreach ($lines as $line) {
                // Apply against live on-hand so completion leaves counted qty, even if stock moved after snapshot.
                $liveQty = $this->stockOnHand(
                    (string) $line->product_code,
                    (int) $session->branch_id,
                    (string) $line->stock_location,
                );
                $variance = (float) $line->counted_quantity - $liveQty;

                $line->system_quantity = $liveQty;
                $line->save();

                if (abs($variance) < 0.0001) {
                    continue;
                }

                $unitCost = max(0, (float) (Product::query()
                    ->where('organization_id', $orgId)
                    ->where('product_code', $line->product_code)
                    ->value('last_cost_price') ?? 0));

                $this->postStockLedger([
                    'branch_id' => $session->branch_id,
                    'product_code' => $line->product_code,
                    'stock_location' => $line->stock_location,
                    'transaction_type' => 'STOCK_TAKE',
                    'reference_type' => 'stock_take_session',
                    'reference_id' => $session->id,
                    'quantity_change' => $variance,
                    'unit_cost' => $unitCost > 0 ? $unitCost : null,
                    'created_by' => $user->id,
                    'notes' => 'Stock take variance',
                ], true);
            }

            $session->update([
                'status' => 'completed',
                'completed_by' => $user->id,
                'completed_at' => now(),
            ]);

            $gate = $this->erp->gateForUser($user);
            app(StockTakeJournalService::class)->postIfEnabled($session->fresh(), $lines, $user, $gate);

            return $session->fresh();
        });
    }
}
