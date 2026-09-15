<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupplierPayment;
use App\Services\Erp\ErpContext;
use App\Services\SupplierModuleService;
use Illuminate\Http\Request;

class SupplierPaymentController extends Controller
{
    public function __construct(
        protected SupplierModuleService $supplierModule,
        protected ErpContext $erp,
    ) {}

    public function index(Request $request)
    {
        $organizationId = (int) $request->user()->organization_id;

        return response()->json(
            $this->supplierModule->listPayments($request, $organizationId),
        );
    }

    public function destroy(Request $request, string $supplier_payment)
    {
        $user = $request->user();
        $payment = SupplierPayment::query()
            ->whereKey((int) $supplier_payment)
            ->where('organization_id', (int) $user->organization_id)
            ->firstOrFail();

        $this->supplierModule->voidPayment(
            $payment,
            $user,
            $this->erp->gateForUser($user),
        );

        return response()->json(['ok' => true]);
    }
}
