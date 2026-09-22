<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StockReceipt;
use App\Services\Auth\UserAccessService;
use App\Services\Inventory\ExpiringBatchService;
use Illuminate\Http\Request;

class ExpiringBatchController extends Controller
{
    public function __construct(
        protected ExpiringBatchService $expiring,
        protected UserAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $organizationId = (int) ($this->access->organizationId($user, $request) ?? $user->organization_id ?? 0);
        abort_unless($organizationId > 0, 403);

        return response()->json(
            $this->expiring->list($request, $user, $organizationId),
        );
    }

    public function clear(Request $request, int|string $id)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $organizationId = (int) ($this->access->organizationId($user, $request) ?? $user->organization_id ?? 0);

        $data = $request->validate([
            'quantity' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        $receipt = StockReceipt::query()
            ->whereKey((int) $id)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $this->access->assertBranchAccess($user, (int) $receipt->branch_id);

        $result = $this->expiring->clear($receipt, $user, $data);
        $status = ! empty($result['pending_approval']) ? 202 : 200;

        return response()->json($result, $status);
    }
}
