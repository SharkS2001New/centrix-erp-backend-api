<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesBranchScope;
use App\Models\PodRecord;
use App\Services\Fulfillment\PodService;
use Illuminate\Http\Request;
use App\Support\StoredPublicFile;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class PodRecordController extends BaseResourceController
{
    use HandlesBranchScope;

    public function __construct(protected PodService $podService) {}

    protected function modelClass(): string
    {
        return PodRecord::class;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request)->with(['sale', 'trip', 'capturedByUser']);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, ['sale_id', 'trip_id', 'status', 'branch_id'], true)) {
                $query->where($col, $val);
            }
        }

        if ($from = $request->input('from_date')) {
            $query->whereDate('captured_at', '>=', $from);
        }
        if ($to = $request->input('to_date')) {
            $query->whereDate('captured_at', '<=', $to);
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('recipient_name', 'like', "%{$q}%")
                    ->orWhereHas('sale', fn ($sale) => $sale->where('order_num', 'like', "%{$q}%"));
                if (ctype_digit($q)) {
                    $inner->orWhere('sale_id', (int) $q);
                }
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('captured_at')->paginate($perPage));
    }

    public function show(Request $request, string $id)
    {
        $record = $this->findBranchScopedModel(PodRecord::class, $id, $request->user(), 'id', $request);

        return response()->json($record->load(['lines.saleItem', 'sale', 'trip', 'capturedByUser']));
    }

    /** POST /sales/orders/{saleId}/pod — JSON or multipart */
    public function storeForSale(Request $request, int $saleId)
    {
        $sale = $this->findScopedSale($saleId, $request->user(), $request);

        $request->validate([
            'recipient_name' => 'sometimes|string|max:200',
            'pod_signer_name' => 'sometimes|string|max:200',
            'notes' => 'nullable|string|max:2000',
            'pod_notes' => 'nullable|string|max:2000',
            'trip_id' => 'nullable|integer|exists:dispatch_trips,id',
            'status' => 'sometimes|in:complete,partial,refused',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'signature' => 'nullable|image|mimes:jpeg,jpg,png|max:2048',
            'lines' => 'sometimes',
            'lines.*.sale_item_id' => 'required_with:lines|integer|exists:sale_items,id',
            'lines.*.qty_delivered' => 'nullable|numeric|min:0',
            'lines.*.qty_refused' => 'nullable|numeric|min:0',
            'lines.*.reason' => 'nullable|string|max:255',
        ]);

        $data = $request->only([
            'recipient_name', 'pod_signer_name', 'notes', 'pod_notes', 'trip_id',
            'status', 'gps_lat', 'gps_lng',
        ]);

        if ($request->has('lines')) {
            $lines = $request->input('lines');
            $data['lines'] = is_string($lines) ? json_decode($lines, true) : $lines;
        }

        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo');
        }
        if ($request->hasFile('signature')) {
            $data['signature'] = $request->file('signature');
        }

        try {
            $record = $this->podService->capture($request->user(), $sale, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['pod_record' => $record], 201);
    }

    public function photoFile(Request $request, int $podRecord)
    {
        return $this->serveFile($request, $podRecord, 'photo_path');
    }

    public function signatureFile(Request $request, int $podRecord)
    {
        return $this->serveFile($request, $podRecord, 'signature_path');
    }

    protected function serveFile(Request $request, int $podRecord, string $column)
    {
        $record = $this->findBranchScopedModel(PodRecord::class, $podRecord, $request->user(), 'id', $request);
        $path = $record->{$column};

        if (! StoredPublicFile::exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return StoredPublicFile::response($path, 'image/jpeg');
    }
}
