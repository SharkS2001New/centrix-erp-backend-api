<?php

namespace App\Http\Controllers\Api\V1\CentrixPayments;

use App\Http\Controllers\Controller;
use App\Models\MpesaIncomingPayment;
use App\Models\MpesaStkRequest;
use App\Models\Organization;
use App\Models\SalePayment;
use App\Services\Auth\UserAccessService;
use App\Services\Payments\CentrixPaymentsAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function __construct(protected UserAccessService $access) {}

    public function show(Request $request)
    {
        $org = $this->organization($request);
        $availability = CentrixPaymentsAvailabilityService::forOrganization($org);
        $today = now()->toDateString();

        $successful = 0;
        $pending = 0;
        $failed = 0;
        $unmatched = 0;
        $todayCollections = 0.0;

        if (Schema::hasTable('mpesa_stk_requests')) {
            $stk = MpesaStkRequest::query()->where('organization_id', $org->id);
            $successful += (clone $stk)->whereIn('status', ['completed', 'success', 'paid'])->count();
            $pending += (clone $stk)->whereIn('status', ['pending', 'processing'])->count();
            $failed += (clone $stk)->whereIn('status', ['failed', 'cancelled'])->count();
            $todayCollections += (float) (clone $stk)
                ->whereIn('status', ['completed', 'success', 'paid'])
                ->whereDate('created_at', $today)
                ->sum('amount');
        }

        if (Schema::hasTable('mpesa_incoming_payments')) {
            $incoming = MpesaIncomingPayment::query()->where('organization_id', $org->id);
            $unmatched += (clone $incoming)->whereIn('reconciliation_status', ['pending', 'unmatched'])->count();
            $todayCollections += (float) (clone $incoming)
                ->whereDate('created_at', $today)
                ->sum('amount');
        }

        if (Schema::hasTable('sale_payments')) {
            $todayCollections += (float) SalePayment::query()
                ->whereHas('sale', fn ($q) => $q->where('organization_id', $org->id))
                ->whereDate('paid_at', $today)
                ->sum('amount');
        }

        return response()->json([
            'availability' => $availability->summary($org),
            'totals' => [
                'today_collections' => round($todayCollections, 2),
                'successful_payments' => $successful,
                'pending_payments' => $pending,
                'failed_payments' => $failed,
                'unmatched_payments' => $unmatched,
            ],
        ]);
    }

    protected function organization(Request $request): Organization
    {
        $orgId = (int) ($this->access->organizationId($request->user(), $request) ?? 0);
        if ($orgId <= 0) {
            abort(403, 'Your account is not linked to an organization.');
        }

        return Organization::query()->findOrFail($orgId);
    }
}
