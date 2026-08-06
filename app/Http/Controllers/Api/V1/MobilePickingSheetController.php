<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Fulfillment\MobilePickingSheetService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MobilePickingSheetController extends Controller
{
    public function __construct(
        protected MobilePickingSheetService $sheets,
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

        $routeIdsInput = $request->input('route_ids');
        if (is_string($routeIdsInput) && trim($routeIdsInput) !== '') {
            $routeIdsInput = array_values(array_filter(array_map(
                'intval',
                preg_split('/\s*,\s*/', $routeIdsInput) ?: [],
            ), fn (int $id) => $id > 0));
            $request->merge(['route_ids' => $routeIdsInput]);
        }

        $routeIds = $request->input('route_ids');
        if (is_array($routeIds) && count($routeIds) >= 2) {
            $data = $request->validate([
                'route_ids' => 'required|array|min:2',
                'route_ids.*' => 'integer|min:1',
                'list_date' => 'required|date_format:Y-m-d',
            ]);

            try {
                return response()->json(
                    $this->sheets->combinedSheetDetail(
                        $request->user(),
                        $data['route_ids'],
                        $data['list_date'],
                    ),
                );
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

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

        try {
            $this->sheets->assertAvailable(
                $gate->distributionOpsEnabled(),
                $gate->mobileSalesEnabled(),
            );
        } catch (InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }
    }
}
