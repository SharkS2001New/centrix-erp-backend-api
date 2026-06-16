<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupplierModuleService;
use Illuminate\Http\Request;

class SupplierPaymentController extends Controller
{
    public function __construct(
        protected SupplierModuleService $supplierModule,
    ) {}

    public function index(Request $request)
    {
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->supplierModule->listPayments($request, $organizationId);

        return response()->json(['data' => $data]);
    }
}
