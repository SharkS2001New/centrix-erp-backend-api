<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformLicencePayment;
use App\Services\Platform\PlatformPaybillLicenceService;
use Illuminate\Http\Request;

class PlatformPaybillController extends Controller
{
    public function __construct(protected PlatformPaybillLicenceService $paybill) {}

    public function show()
    {
        $settings = $this->paybill->settings();

        return response()->json([
            'data' => [
                'short_code' => $settings?->short_code ?? '',
                'account_name_prefix' => $settings?->account_name_prefix ?? '',
                'organization_id' => $settings?->organization_id,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'short_code' => 'nullable|string|max:20',
            'account_name_prefix' => 'nullable|string|max:40',
        ]);

        $settings = $this->paybill->saveSettings($data);

        return response()->json([
            'data' => [
                'short_code' => $settings->short_code ?? '',
                'account_name_prefix' => $settings->account_name_prefix ?? '',
                'organization_id' => $settings->organization_id,
            ],
            'message' => 'Centrix Paybill saved.',
        ]);
    }

    public function payments(Request $request)
    {
        $query = PlatformLicencePayment::query()
            ->with('organization:id,company_code,org_name,primary_tel')
            ->orderByDesc('id');

        $status = trim((string) $request->query('status', ''));
        if (in_array($status, ['applied', 'unmatched'], true)) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query->limit(100)->get(),
        ]);
    }
}
