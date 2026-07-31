<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Sales\BackofficeOrderLineEditService;
use App\Services\Sales\SaleMergeService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SaleMergeController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected SaleMergeService $merges,
        protected BackofficeOrderLineEditService $lineEdits,
    ) {}

    public function merge(Request $request)
    {
        $data = $request->validate([
            'sale_ids' => 'required|array|min:2',
            'sale_ids.*' => 'integer|distinct|min:1',
            'target_sale_id' => 'sometimes|nullable|integer|min:1',
        ]);

        $saleIds = array_map('intval', $data['sale_ids']);
        $targetSaleId = isset($data['target_sale_id']) ? (int) $data['target_sale_id'] : null;
        if ($targetSaleId && ! in_array($targetSaleId, $saleIds, true)) {
            throw ValidationException::withMessages([
                'target_sale_id' => 'The keep-order must be one of the selected orders.',
            ]);
        }

        $user = $request->user();
        $gate = $this->erp->gateForUser($user);

        try {
            $merged = $this->merges->merge($saleIds, $user, $gate, $targetSaleId);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'sale_ids' => $e->getMessage(),
            ]);
        }

        $channel = $merged->channel ?: 'mobile';

        return response()->json(array_merge($merged->toArray(), [
            'can_edit_lines' => $this->lineEdits->canEditLineQuantities($merged, $user, $gate),
            'workflow_status' => OrderWorkflowService::forGate($gate)->alignStatusToPipeline(
                (string) $merged->status,
                $channel,
            ),
            'merged_source_count' => count($saleIds) - 1,
        ]));
    }
}
