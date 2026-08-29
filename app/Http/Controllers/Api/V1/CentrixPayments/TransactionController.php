<?php

namespace App\Http\Controllers\Api\V1\CentrixPayments;

use App\Http\Controllers\Controller;
use App\Services\Auth\UserAccessService;
use App\Services\Payments\PaymentTransactionService;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        protected UserAccessService $access,
        protected PaymentTransactionService $transactions,
    ) {}

    public function index(Request $request)
    {
        $orgId = (int) ($this->access->organizationId($request->user(), $request) ?? 0);
        if ($orgId <= 0) {
            abort(403, 'Your account is not linked to an organization.');
        }

        $payload = $this->transactions->listForOrganization($orgId, [
            'limit' => $request->integer('limit', 50),
            'status' => $request->query('status'),
        ]);

        return response()->json($payload);
    }
}
