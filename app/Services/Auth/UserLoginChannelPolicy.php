<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;
use Illuminate\Validation\ValidationException;

class UserLoginChannelPolicy
{
    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    public function sanitizeForOrganization(Organization $organization, array $channels): array
    {
        $gate = app(CapabilityGate::class)->forOrganization($organization);
        if ($gate->mobileSalesEnabled()) {
            return app(UserLoginChannelService::class)->normalize($channels);
        }

        $filtered = array_values(array_filter(
            app(UserLoginChannelService::class)->normalize($channels),
            fn (string $channel) => $channel !== UserLoginChannelService::MOBILE,
        ));

        return $filtered === []
            ? [UserLoginChannelService::BACKOFFICE]
            : $filtered;
    }

    /**
     * @param  list<string>  $channels
     */
    public function assertAllowedForOrganization(Organization $organization, array $channels): void
    {
        $gate = app(CapabilityGate::class)->forOrganization($organization);
        if ($gate->mobileSalesEnabled()) {
            return;
        }

        if (in_array(UserLoginChannelService::MOBILE, $channels, true)) {
            throw ValidationException::withMessages([
                'login_channels' => ['Mobile users cannot be created while mobile orders are disabled for this organization.'],
            ]);
        }
    }
}
