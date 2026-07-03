<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockTransferRequest;
use App\Services\Auth\UserAccessService;
use App\Services\Erp\ErpContext;
use App\Services\Inventory\StockTransferApprovalService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StockTransferController extends Controller
{
    public function __construct(
        protected StockTransferService $transfers,
        protected StockTransferApprovalService $approval,
        protected ErpContext $erp,
        protected UserAccessService $access,
    ) {}

    public function store(StockTransferRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();
        abort_unless($user, 401);

        $gate = $this->erp->gateForUser($user);

        if ($this->approval->requiresApproval($gate, $user, $data['from_location'], $data['to_location'])) {
            throw ValidationException::withMessages([
                'authorization' => 'This transfer requires manager approval. Submit a request instead.',
            ]);
        }

        $this->access->assertBranchAccess($user, (int) $data['branch_id']);

        $result = $this->transfers->transfer(
            (int) $data['branch_id'],
            (string) $data['product_code'],
            (float) $data['quantity'],
            (string) $data['from_location'],
            (string) $data['to_location'],
            $user,
            $data['notes'] ?? null,
        );

        return response()->json($result, 201);
    }

    public function requestTransfer(StockTransferRequest $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $gate = $this->erp->gateForUser($user);
        $actionRequest = $this->approval->requestTransfer($user, $request->validated(), $gate);

        return response()->json([
            'message' => 'Stock transfer submitted for manager approval.',
            'pending_approval' => true,
            'action_request_id' => (int) $actionRequest->id,
        ], 202);
    }
}
