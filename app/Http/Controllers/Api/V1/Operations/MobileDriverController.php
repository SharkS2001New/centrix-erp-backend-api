<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Fulfillment\MobileDriverService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;

class MobileDriverController extends Controller
{
    public function __construct(protected MobileDriverService $driverService) {}

    /** GET /mobile/driver/trips/today */
    public function todayTrips(Request $request)
    {
        return response()->json($this->driverService->todayTrips($request->user()));
    }

    /** GET /mobile/driver/trips/{tripId} */
    public function showTrip(Request $request, int $tripId)
    {
        return response()->json($this->driverService->showTrip($request->user(), $tripId));
    }

    /** GET /mobile/driver/trips/{tripId}/stops */
    public function tripStops(Request $request, int $tripId)
    {
        return response()->json([
            'stops' => $this->driverService->tripStops($request->user(), $tripId),
        ]);
    }

    /** GET /mobile/driver/stops/{saleId} */
    public function showStop(Request $request, int $saleId)
    {
        return response()->json($this->driverService->showStop($request->user(), $saleId));
    }

    /** POST /mobile/driver/stops/{saleId}/deliver — multipart POD + mark delivered */
    public function deliverStop(Request $request, int $saleId)
    {
        $data = $request->validate([
            'recipient_name' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:2000',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'collect_amount' => 'nullable|numeric|min:0',
            'payment_method_code' => 'nullable|string|max:40',
            'payment_reference' => 'nullable|string|max:120',
            'delivery_outcome' => 'nullable|in:complete,partial,failed',
            'failure_reason' => 'nullable|string|max:255',
            'return_reason' => 'nullable|string|max:255',
            'lines' => 'nullable',
        ]);

        if (isset($data['lines'])) {
            $decoded = is_string($data['lines'])
                ? json_decode((string) $data['lines'], true)
                : $data['lines'];
            if (! is_array($decoded)) {
                throw ValidationException::withMessages([
                    'lines' => ['Delivery lines must be valid JSON.'],
                ]);
            }
            $data['lines'] = $decoded;
        }

        try {
            $stop = $this->driverService->deliverStop(
                $request->user(),
                $saleId,
                $data,
                $request->file('photo'),
            );

            return response()->json([
                'message' => 'Order marked as delivered.',
                'stop' => $stop,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /mobile/driver/trips/{tripId}/settle — record collected COD cash */
    public function settleTrip(Request $request, int $tripId)
    {
        $data = $request->validate([
            'collected_cash' => 'required|numeric|min:0',
        ]);

        try {
            return response()->json(
                $this->driverService->settleTrip($request->user(), $tripId, $data),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
