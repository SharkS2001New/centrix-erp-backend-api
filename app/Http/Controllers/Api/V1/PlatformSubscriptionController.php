<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformInvoice;
use App\Models\PlatformSubscription;
use App\Services\Platform\OrganizationLicenseService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class PlatformSubscriptionController extends Controller
{
    public function __construct(protected OrganizationLicenseService $licenses) {}

    public function index(Request $request)
    {
        $q = PlatformSubscription::query()->with(['organization', 'plan', 'invoice'])->orderByDesc('id');
        if ($request->filled('status') && $request->status !== 'all') {
            $q->where('status', $request->status);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
            'plan_id' => 'nullable|integer|exists:platform_plans,id',
            'status' => 'sometimes|string|max:30',
            'seat_count' => 'sometimes|integer|min:1',
            'current_period_start' => 'nullable|date',
            'current_period_end' => 'nullable|date',
            'is_trial' => 'sometimes|boolean',
            'trial_days' => 'nullable|integer|min:1',
            'trial_ends_at' => 'nullable|date',
            'first_payment_price' => 'nullable|numeric|min:0',
            'renewal_price' => 'nullable|numeric|min:0',
            'currency' => 'sometimes|string|max:8',
            'license_basis' => 'sometimes|in:org,user',
            'workspace_keys' => 'sometimes|array',
            'module_keys' => 'sometimes|array',
            'contract_id' => 'nullable|integer',
            'invoice_id' => 'nullable|integer|exists:platform_invoices,id',
        ]);

        $this->assertInvoiceBelongsToOrganization(
            $data['invoice_id'] ?? null,
            (int) $data['organization_id'],
        );

        $org = Organization::query()->findOrFail($data['organization_id']);
        $sub = $this->licenses->createOrUpdateForOrganization($org, $data);

        return response()->json(['data' => $sub, 'message' => 'Subscription assigned.'], 201);
    }

    public function update(Request $request, PlatformSubscription $platform_subscription)
    {
        $data = $request->validate([
            'plan_id' => 'nullable|integer|exists:platform_plans,id',
            'status' => 'sometimes|string|max:30',
            'seat_count' => 'sometimes|integer|min:1',
            'current_period_start' => 'nullable|date',
            'current_period_end' => 'nullable|date',
            'is_trial' => 'sometimes|boolean',
            'trial_ends_at' => 'nullable|date',
            'first_payment_price' => 'nullable|numeric|min:0',
            'renewal_price' => 'nullable|numeric|min:0',
            'license_basis' => 'sometimes|in:org,user',
            'workspace_keys' => 'sometimes|array',
            'module_keys' => 'sometimes|array',
            'invoice_id' => 'nullable|integer|exists:platform_invoices,id',
        ]);

        if (array_key_exists('invoice_id', $data)) {
            $this->assertInvoiceBelongsToOrganization(
                $data['invoice_id'],
                (int) $platform_subscription->organization_id,
            );
        }

        $platform_subscription->update($data);

        return response()->json([
            'data' => $platform_subscription->fresh()->load(['organization', 'plan', 'invoice']),
            'message' => 'Subscription updated.',
        ]);
    }

    public function destroy(PlatformSubscription $platform_subscription)
    {
        $platform_subscription->delete();

        return response()->json(['message' => 'Subscription deleted.']);
    }

    public function extend(Request $request, PlatformSubscription $platform_subscription)
    {
        $data = $request->validate([
            'days' => 'nullable|integer|min:1',
            'current_period_end' => 'nullable|date',
            'status' => 'sometimes|string|max:30',
        ]);

        $end = $data['current_period_end']
            ?? Carbon::parse($platform_subscription->current_period_end ?? now())
                ->addDays((int) ($data['days'] ?? 30))
                ->toDateString();

        $platform_subscription->update([
            'current_period_end' => $end,
            'trial_ends_at' => $platform_subscription->is_trial ? $end : $platform_subscription->trial_ends_at,
            'status' => $data['status'] ?? (in_array($platform_subscription->status, ['expired', 'past_due', 'cancelled'], true)
                ? 'active'
                : $platform_subscription->status),
        ]);

        return response()->json([
            'data' => $platform_subscription->fresh()->load(['organization', 'plan', 'invoice']),
            'message' => 'Licence extended.',
        ]);
    }

    public function draftInvoice(PlatformSubscription $platform_subscription)
    {
        // Minimal stub: point frontend at invoice create with org preselected.
        return response()->json([
            'message' => 'Open a new platform invoice for this organization.',
            'data' => [
                'organization_id' => $platform_subscription->organization_id,
                'subscription_id' => $platform_subscription->id,
            ],
        ]);
    }

    public function forOrganization(Organization $organization)
    {
        $sub = PlatformSubscription::query()
            ->with(['plan', 'invoice'])
            ->where('organization_id', $organization->id)
            ->first();

        return response()->json(['data' => $sub]);
    }

    public function tenantSubscription(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['data' => null]);
        }

        $sub = PlatformSubscription::query()
            ->with(['plan', 'invoice'])
            ->where('organization_id', $org->id)
            ->first();

        return response()->json(['data' => $sub]);
    }

    protected function assertInvoiceBelongsToOrganization(?int $invoiceId, int $organizationId): void
    {
        if ($invoiceId === null) {
            return;
        }

        $invoice = PlatformInvoice::query()->find($invoiceId);
        if (! $invoice || (int) $invoice->organization_id !== $organizationId) {
            throw new HttpResponseException(response()->json([
                'message' => 'Invoice must belong to the same organization as the subscription.',
            ], 422));
        }
    }
}
