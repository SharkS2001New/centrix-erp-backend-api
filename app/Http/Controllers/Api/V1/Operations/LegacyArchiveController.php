<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Legacy\LegacyArchiveReader;
use App\Services\Legacy\LightStoresLegacyImporter;
use App\Services\Legacy\OrganizationLegacyArchiveService;
use App\Support\AppTimezone;
use Illuminate\Http\Request;

class LegacyArchiveController extends Controller
{
    public function __construct(
        protected LegacyArchiveReader $archive,
        protected ErpContext $erp,
    ) {}

    protected function tenantOrganization(Request $request): Organization
    {
        return $this->erp->resolveOrganization($request);
    }

    /** GET /reports/legacy-archive/status */
    public function status(Request $request)
    {
        $org = $this->tenantOrganization($request);

        return response()->json($this->archive->status($org));
    }

    /** GET /reports/legacy-archive/summary */
    public function summary(Request $request)
    {
        $org = $this->tenantOrganization($request);

        if (! $this->archive->isAvailable($org)) {
            return response()->json([
                'message' => 'Legacy archive is not enabled or the LightStores database is not reachable for this organization.',
            ], 503);
        }

        $data = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $from = isset($data['from_date']) ? AppTimezone::parseDateStart((string) $data['from_date']) : null;
        $to = isset($data['to_date']) ? AppTimezone::parseDateEnd((string) $data['to_date']) : null;

        return response()->json([
            'archive' => $this->archive->status($org),
            'summary' => $this->archive->salesSummary($org, $from, $to),
            'from_date' => $from?->toDateString(),
            'to_date' => $to?->toDateString(),
        ]);
    }

    /** GET /reports/legacy-archive/sales/{channel}/{legacyOrderNum} */
    public function showSale(Request $request, string $channel, int $legacyOrderNum)
    {
        $org = $this->tenantOrganization($request);

        if (! $this->archive->isAvailable($org)) {
            return response()->json([
                'message' => 'Legacy archive is not enabled or the LightStores database is not reachable for this organization.',
            ], 503);
        }

        if (! in_array($channel, ['pos', 'mobile', 'debtor'], true)) {
            return response()->json(['message' => 'Invalid channel.'], 422);
        }

        try {
            $data = $request->validate([
                'sale_date' => 'required|date',
            ]);

            if ($request->boolean('for_print')) {
                return response()->json($this->archive->saleForPrint(
                    $org,
                    $channel,
                    $legacyOrderNum,
                    $data['sale_date'],
                ));
            }

            return response()->json($this->archive->saleDetail(
                $org,
                $channel,
                $legacyOrderNum,
                $data['sale_date'],
            ));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /** GET /reports/legacy-archive/sales */
    public function sales(Request $request)
    {
        $org = $this->tenantOrganization($request);

        if (! $this->archive->isAvailable($org)) {
            return response()->json([
                'message' => 'Legacy archive is not enabled or the LightStores database is not reachable for this organization.',
            ], 503);
        }

        $filters = $request->validate([
            'channel' => 'required|in:pos,mobile,debtor,all',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'q' => 'nullable|string|max:120',
            'min_order_total' => 'nullable|numeric|min:0',
            'max_order_total' => 'nullable|numeric|min:0',
            'order_total' => 'nullable|numeric|min:0',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $from = AppTimezone::parseDateStart((string) $filters['from_date']);
        $to = AppTimezone::parseDateEnd((string) $filters['to_date']);
        if ($from->diffInDays($to) > 366) {
            return response()->json([
                'message' => 'Date range cannot exceed 366 days. Narrow the range and try again.',
            ], 422);
        }

        return response()->json($this->archive->listSales($org, $filters));
    }

    /** POST /reports/legacy-archive/sales/materialize */
    public function materialize(Request $request, LightStoresLegacyImporter $importer)
    {
        $org = $this->tenantOrganization($request);

        if (! $this->archive->isAvailable($org)) {
            return response()->json([
                'message' => 'Legacy archive is not enabled or the LightStores database is not reachable for this organization.',
            ], 503);
        }

        $data = $request->validate([
            'channel' => 'required|in:pos,mobile,debtor',
            'legacy_order_num' => 'required|integer|min:1',
            'sale_date' => 'required|date',
        ]);

        $existingId = $this->archive->findMaterializedSaleId(
            $org,
            $data['channel'],
            (int) $data['legacy_order_num'],
            $data['sale_date'],
        );

        if ($existingId) {
            return response()->json([
                'message' => 'This legacy order has already been materialized into Centrix.',
                'materialized_sale_id' => $existingId,
            ], 422);
        }

        try {
            $sale = $importer->materializeSale(
                $org,
                $data['channel'],
                (int) $data['legacy_order_num'],
                $data['sale_date'],
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Legacy sale is now available in Centrix for returns and credit notes.',
            'sale' => $sale,
            'return_lines_path' => '/api/v1/sales/'.$sale->id.'/return-lines',
            'customer_returns_path' => '/api/v1/customer-returns',
        ], 201);
    }
}
