<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Sales\BackofficeOrderLineEditService;
use Illuminate\Http\Request;

class BackofficeOrderLineEditController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected BackofficeOrderLineEditService $lineEdits,
    ) {}

    public function updateLineQuantities(Request $request, int $saleId)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.discount_given' => 'sometimes|numeric|min:0',
        ]);

        $user = $request->user();
        $sale = Sale::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($saleId);

        $updated = $this->lineEdits->updateLineQuantities($sale, $user, $data['items'], $this->erp->gateForUser($user));
        $gate = $this->erp->gateForUser($user);
        $channel = $updated->channel ?: 'backend';

        return response()->json(array_merge($updated->toArray(), [
            'can_edit_lines' => $this->lineEdits->canEditLineQuantities($updated, $user, $gate),
            'workflow_status' => OrderWorkflowService::forGate($gate)->alignStatusToPipeline(
                (string) $updated->status,
                $channel,
            ),
        ]));
    }
}
