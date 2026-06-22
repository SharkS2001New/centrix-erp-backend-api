<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyArchiveReader;
use App\Services\Legacy\LightStoresLegacyImporter;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LegacyArchiveController extends Controller
{
    public function __construct(protected LegacyArchiveReader $archive) {}

    /** GET /reports/legacy-archive/status */
    public function status()
    {
        return response()->json($this->archive->status());
    }

    /** GET /reports/legacy-archive/summary */
    public function summary(Request $request)
    {
        if (! $this->archive->isAvailable()) {
            return response()->json([
                'message' => 'Legacy archive is not enabled or the LightStores database is not reachable.',
            ], 503);
        }

        $data = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $from = isset($data['from_date']) ? Carbon::parse($data['from_date'])->startOfDay() : null;
        $to = isset($data['to_date']) ? Carbon::parse($data['to_date'])->endOfDay() : null;

        return response()->json([
            'archive' => $this->archive->status(),
            'summary' => $this->archive->salesSummary($from, $to),
            'from_date' => $from?->toDateString(),
            'to_date' => $to?->toDateString(),
        ]);
    }

    /** GET /reports/legacy-archive/sales */
    public function sales(Request $request)
    {
        if (! $this->archive->isAvailable()) {
            return response()->json([
                'message' => 'Legacy archive is not enabled or the LightStores database is not reachable.',
            ], 503);
        }

        $filters = $request->validate([
            'channel' => 'required|in:pos,mobile,debtor',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'q' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        return response()->json($this->archive->listSales($filters, (int) $request->user()->organization_id));
    }

    /** POST /reports/legacy-archive/sales/materialize — import one legacy sale into Centrix for returns / credit notes */
    public function materialize(Request $request, LightStoresLegacyImporter $importer)
    {
        if (! $this->archive->isAvailable()) {
            return response()->json([
                'message' => 'Legacy archive is not enabled or the LightStores database is not reachable.',
            ], 503);
        }

        $data = $request->validate([
            'channel' => 'required|in:pos,mobile,debtor',
            'legacy_order_num' => 'required|integer|min:1',
        ]);

        try {
            $sale = $importer->materializeSale($data['channel'], (int) $data['legacy_order_num']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ((int) $sale->organization_id !== (int) $request->user()->organization_id) {
            abort(403);
        }

        return response()->json([
            'message' => 'Legacy sale is now available in Centrix for returns and credit notes.',
            'sale' => $sale,
            'return_lines_path' => '/api/v1/sales/'.$sale->id.'/return-lines',
            'customer_returns_path' => '/api/v1/customer-returns',
        ], 201);
    }
}
