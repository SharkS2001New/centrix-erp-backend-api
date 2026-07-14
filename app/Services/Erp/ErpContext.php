<?php

namespace App\Services\Erp;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;

class ErpContext
{
    public function organizationForUser(?User $user): ?Organization
    {
        if (! $user?->organization_id) {
            return null;
        }

        $orgId = (int) $user->organization_id;
        $request = $this->currentRequest();
        $attrKey = 'erp_context.org.'.$orgId;
        if ($request?->attributes->has($attrKey)) {
            return $request->attributes->get($attrKey);
        }

        $org = Organization::find($orgId);
        $request?->attributes->set($attrKey, $org);

        return $org;
    }

    public function gateForUser(?User $user): CapabilityGate
    {
        if (! $user) {
            return new CapabilityGate;
        }

        $request = $this->currentRequest();
        $attrKey = 'erp_context.gate.user.'.(int) $user->id;
        if ($request?->attributes->has($attrKey)) {
            return $request->attributes->get($attrKey);
        }

        $org = $this->organizationForUser($user);
        $gate = $org
            ? $this->gateForOrganization($org)
            : new CapabilityGate;
        $request?->attributes->set($attrKey, $gate);

        return $gate;
    }

    public function gateForOrganization(Organization $organization): CapabilityGate
    {
        $request = $this->currentRequest();
        $attrKey = 'erp_context.gate.org.'.(int) $organization->id;
        if ($request?->attributes->has($attrKey)) {
            return $request->attributes->get($attrKey);
        }

        $gate = (new CapabilityGate)->forOrganization($organization);
        $request?->attributes->set($attrKey, $gate);

        return $gate;
    }

    protected function currentRequest(): ?Request
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        try {
            return request();
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolveOrganization(Request $request): Organization
    {
        if ($actingId = $request->attributes->get('acting_organization_id')) {
            return Organization::findOrFail($actingId);
        }

        $user = $request->user();
        if (! $user?->organization_id) {
            abort(403, 'Organization context required.');
        }

        return Organization::findOrFail($user->organization_id);
    }

    public function gateForRequest(Request $request): CapabilityGate
    {
        return $this->gateForOrganization($this->resolveOrganization($request));
    }
}
