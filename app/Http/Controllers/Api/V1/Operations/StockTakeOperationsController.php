<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Controller;
use App\Models\StockTakeLine;
use App\Models\StockTakeSession;
use App\Models\User;
use App\Services\Accounting\StockTakeJournalService;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockTakeOperationsController extends Controller
{
    use HandlesInventory;

    public function __construct(protected ErpContext $erp) {}

    public function complete(Request $request, int $sessionId)
    {
        $session = StockTakeSession::findOrFail($sessionId);

        return response()->json(
            $this->completeStockTakeSession($session, $request->user())
        );
    }

    protected function completeStockTakeSession(StockTakeSession $session, User $user): StockTakeSession
    {
        if ($session->status === 'completed') {
            throw new InvalidArgumentException('Session already completed.');
        }

        return DB::transaction(function () use ($session, $user) {
            $lines = StockTakeLine::where('session_id', $session->id)->get();

            foreach ($lines as $line) {
                $variance = (float) $line->counted_quantity - (float) $line->system_quantity;
                if (abs($variance) < 0.0001) {
                    continue;
                }

                $this->postStockLedger([
                    'branch_id' => $session->branch_id,
                    'product_code' => $line->product_code,
                    'stock_location' => $line->stock_location,
                    'transaction_type' => 'STOCK_TAKE',
                    'reference_type' => 'stock_take_session',
                    'reference_id' => $session->id,
                    'quantity_change' => $variance,
                    'created_by' => $user->id,
                    'notes' => 'Stock take variance',
                ]);
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
