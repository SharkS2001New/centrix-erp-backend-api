<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Fulfillment\MobileLoadingSheetService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MobileLoadingSheetController extends Controller
{
    public function __construct(
        protected MobileLoadingSheetService $sheets,
        protected ErpContext $erp,
    ) {}

    public function index(Request $request)
    {
        $this->assertFeatureAvailable($request);

        return response()->json([
            'data' => $this->sheets->listSheets($request->user(), [
                'route_id' => $request->input('route_id'),
                'from_date' => $request->input('from_date'),
                'to_date' => $request->input('to_date'),
            ]),
        ]);
    }

    public function show(Request $request)
    {
        $this->assertFeatureAvailable($request);

        $data = $request->validate([
            'route_id' => 'required|integer|min:1',
            'list_date' => 'required|date_format:Y-m-d',
        ]);

        try {
            return response()->json(
                $this->sheets->sheetDetail(
                    $request->user(),
                    (int) $data['route_id'],
                    $data['list_date'],
                ),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function assertFeatureAvailable(Request $request): void
    {
        $gate = $this->erp->gateForUser($request->user());
        $sales = $gate->moduleSettings('sales');

        try {
            $this->sheets->assertAvailable(
                $gate->enabled('distribution'),
                $gate->enabled('sales.mobile') && (bool) ($sales['enable_mobile_orders'] ?? true),
            );
        } catch (InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }
    }
}
