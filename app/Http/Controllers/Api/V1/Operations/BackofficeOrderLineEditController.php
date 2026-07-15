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
            'items.*.id' => 'nullable|integer',
            'items.*.product_code' => 'nullable|string|max:64',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.discount_given' => 'sometimes|numeric|min:0',
            'items.*.on_wholesale_retail' => 'sometimes|boolean',
            'remove_item_ids' => 'sometimes|array',
            'remove_item_ids.*' => 'integer',
            'customer_num' => 'sometimes|integer|min:1',
        ]);

        foreach ($data['items'] as $index => $row) {
            if (empty($row['id']) && empty($row['product_code'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$index}" => 'Each line must include an existing item id or a product_code.',
                ]);
            }
        }

        $user = $request->user();
        $sale = Sale::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($saleId);

        $updated = $this->lineEdits->updateLineQuantities(
            $sale,
            $user,
            $data['items'],
            $this->erp->gateForUser($user),
            $data['remove_item_ids'] ?? [],
            array_key_exists('customer_num', $data) ? (int) $data['customer_num'] : null,
        );
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
