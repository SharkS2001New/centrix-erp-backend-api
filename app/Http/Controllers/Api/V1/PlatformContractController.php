<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformContract;
use App\Services\Platform\OrganizationLicenseService;
use App\Services\Platform\PlatformMailSettingsResolver;
use Illuminate\Http\Request;

class PlatformContractController extends Controller
{
    public function __construct(protected OrganizationLicenseService $licenses) {}

    public function index(Request $request)
    {
        $q = PlatformContract::query()->with(['organization', 'plan'])->orderByDesc('id');
        if ($request->filled('kind') && $request->kind !== 'all') {
            $q->where('kind', $request->kind);
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $q->where('status', $request->status);
        }
        if ($request->filled('organization_id')) {
            $q->where('organization_id', $request->organization_id);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $contract = PlatformContract::query()->create($data);

        return response()->json(['data' => $contract->load(['organization', 'plan']), 'message' => 'Saved.'], 201);
    }

    public function show(PlatformContract $platform_contract)
    {
        return response()->json(['data' => $platform_contract->load(['organization', 'plan'])]);
    }

    public function update(Request $request, PlatformContract $platform_contract)
    {
        $platform_contract->update($this->validated($request, false));

        return response()->json([
            'data' => $platform_contract->fresh()->load(['organization', 'plan']),
            'message' => 'Saved.',
        ]);
    }

    public function destroy(PlatformContract $platform_contract)
    {
        $platform_contract->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function accept(PlatformContract $platform_contract)
    {
        $platform_contract->update([
            'status' => 'accepted',
            'kind' => $platform_contract->kind === 'quote' ? 'contract' : $platform_contract->kind,
        ]);

        return response()->json([
            'data' => $platform_contract->fresh(),
            'message' => 'Quote accepted.',
        ]);
    }

    public function provision(PlatformContract $platform_contract)
    {
        $org = $platform_contract->organization;
        if (! $org) {
            return response()->json([
                'message' => 'Link an organization on the contract, or register the org first then attach it.',
            ], 422);
        }

        $sub = $this->licenses->createOrUpdateForOrganization($org, [
            'plan_id' => $platform_contract->plan_id,
            'status' => 'active',
            'seat_count' => $platform_contract->seat_count,
            'current_period_start' => $platform_contract->start_date?->toDateString() ?? now()->toDateString(),
            'current_period_end' => $platform_contract->end_date?->toDateString(),
            'first_payment_price' => $platform_contract->first_payment_price ?? $platform_contract->amount,
            'renewal_price' => $platform_contract->renewal_price ?? $platform_contract->amount,
            'currency' => $platform_contract->currency,
            'license_basis' => $platform_contract->license_basis,
            'workspace_keys' => $platform_contract->workspace_keys,
            'module_keys' => $platform_contract->module_keys,
            'contract_id' => $platform_contract->id,
        ]);

        $platform_contract->update(['status' => 'active']);

        return response()->json([
            'message' => 'Organization provisioned from contract.',
            'organization_id' => $org->id,
            'subscription_id' => $sub->id,
            'data' => [
                'organization_id' => $org->id,
                'subscription_id' => $sub->id,
            ],
        ]);
    }

    public function pdf(PlatformContract $platform_contract)
    {
        $html = '<html><body><h1>'.e($platform_contract->title).'</h1><pre>'.e($platform_contract->terms).'</pre></body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function email(Request $request, PlatformContract $platform_contract)
    {
        $data = $request->validate([
            'to' => 'required|email',
            'subject' => 'nullable|string|max:500',
            'body' => 'nullable|string',
        ]);

        $settings = PlatformMailSettingsResolver::resolve();
        $vars = [
            'kind' => $platform_contract->kind,
            'title' => $platform_contract->title,
            'reference' => $platform_contract->reference ?? (string) $platform_contract->id,
            'customer_name' => $platform_contract->customer_name
                ?? $platform_contract->organization?->org_name
                ?? 'Customer',
            'first_payment' => (string) ($platform_contract->first_payment_price ?? $platform_contract->amount),
            'renewal_payment' => (string) ($platform_contract->renewal_price ?? $platform_contract->amount),
            'from_name' => $settings['from_name'] ?? 'Centrix',
        ];
        $fill = fn (string $tpl) => preg_replace_callback('/\{(\w+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $tpl);

        $subject = $data['subject'] ?? $fill($settings['contract_email_subject'] ?? 'Centrix ERP');
        $body = $data['body'] ?? $fill($settings['contract_email_body'] ?? '');

        PlatformMailSettingsResolver::sendRaw($data['to'], $subject, $body, $request->user(), [
            'kind' => 'contract',
            'contract_id' => $platform_contract->id,
            'organization_id' => $platform_contract->organization_id,
        ]);

        if ($platform_contract->status === 'draft') {
            $platform_contract->update(['status' => 'sent']);
        }

        return response()->json(['message' => 'Sent to '.$data['to'].'.']);
    }

    public function forOrganization(Organization $organization)
    {
        $rows = PlatformContract::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function tenantContracts(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => PlatformContract::query()
                ->where('organization_id', $org->id)
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    protected function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'kind' => 'sometimes|in:quote,contract',
            'status' => 'sometimes|string|max:20',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'plan_id' => 'nullable|integer|exists:platform_plans,id',
            'title' => ($creating ? 'required' : 'sometimes').'|string|max:255',
            'reference' => 'nullable|string|max:100',
            'valid_until' => 'nullable|date',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'currency' => 'sometimes|string|max:8',
            'license_basis' => 'sometimes|in:org,user',
            'amount' => 'nullable|numeric|min:0',
            'first_payment_price' => 'nullable|numeric|min:0',
            'renewal_price' => 'nullable|numeric|min:0',
            'seat_count' => 'sometimes|integer|min:1',
            'workspace_keys' => 'sometimes|array',
            'module_keys' => 'sometimes|array',
            'customer_name' => 'nullable|string|max:200',
            'customer_email' => 'nullable|email|max:200',
            'customer_phone' => 'nullable|string|max:50',
            'customer_address' => 'nullable|string',
            'customer_tax_pin' => 'nullable|string|max:50',
            'terms' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
    }
}
