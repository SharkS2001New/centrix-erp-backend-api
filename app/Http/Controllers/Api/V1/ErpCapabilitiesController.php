<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;

class ErpCapabilitiesController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    /** GET /api/v1/erp/capabilities — what this tenant can use */
    public function show(Request $request)
    {
        $gate = $this->erp->gateForUser($request->user());

        return response()->json($gate->toArray());
    }

    /** GET /api/v1/erp/profiles — deployment profile definitions (for admin UI) */
    public function profiles()
    {
        return response()->json([
            'profiles' => config('erp.profiles'),
            'modules' => config('erp.modules'),
        ]);
    }
}
