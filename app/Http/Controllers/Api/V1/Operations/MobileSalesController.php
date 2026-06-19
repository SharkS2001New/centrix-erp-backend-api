<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Sales\MobileSalesService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MobileSalesController extends Controller
{
    public function __construct(
        protected MobileSalesService $mobileSales,
    ) {}

    /** GET /mobile/dashboard — rep-scoped KPIs and charts for the mobile app. */
    public function dashboard(Request $request)
    {
        $data = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'all_channels' => 'nullable|boolean',
        ]);

        $to = isset($data['to_date'])
            ? Carbon::parse($data['to_date'])->startOfDay()
            : now()->startOfDay();
        $from = isset($data['from_date'])
            ? Carbon::parse($data['from_date'])->startOfDay()
            : $to->copy();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        $allChannels = filter_var($data['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json(
            $this->mobileSales->dashboard($request->user(), $from, $to, $allChannels),
        );
    }

    /** GET /mobile/orders — paginated mobile orders for the signed-in rep. */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'q' => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|min:1|max:200',
            'all_channels' => 'nullable|boolean',
        ]);

        if (array_key_exists('all_channels', $filters)) {
            $filters['all_channels'] = filter_var(
                $filters['all_channels'],
                FILTER_VALIDATE_BOOLEAN,
            );
        }

        return response()->json(
            $this->mobileSales->listOrders($request->user(), $filters),
        );
    }

    /** GET /mobile/orders/{saleId} — order header + line items. */
    public function show(Request $request, int $saleId)
    {
        $data = $request->validate([
            'all_channels' => 'nullable|boolean',
        ]);

        $allChannels = filter_var($data['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json(
            $this->mobileSales->showOrder($request->user(), $saleId, $allChannels),
        );
    }
}
