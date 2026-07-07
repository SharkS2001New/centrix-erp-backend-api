<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;
use Illuminate\Validation\ValidationException;

class UserLoginChannelPolicy
{
    /**
     * @return list<string>
     */
    public function defaultChannelsForOrganization(Organization $organization): array
    {
        return app(CapabilityGate::class)->forOrganization($organization)->allowedLoginChannels();
    }

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    public function sanitizeForOrganization(Organization $organization, array $channels): array
    {
        $allowed = $this->defaultChannelsForOrganization($organization);
        $normalized = app(UserLoginChannelService::class)->normalize($channels);
        $filtered = array_values(array_intersect($normalized, $allowed));

        return $filtered === [] ? $allowed : $filtered;
    }

    /**
     * @param  list<string>  $channels
     */
    public function assertAllowedForOrganization(Organization $organization, array $channels): void
    {
        $allowed = array_flip($this->defaultChannelsForOrganization($organization));
        $normalized = app(UserLoginChannelService::class)->normalize($channels);
        $service = app(UserLoginChannelService::class);

        foreach ($normalized as $channel) {
            if (isset($allowed[$channel])) {
                continue;
            }

            $message = match ($channel) {
                UserLoginChannelService::POS => 'POS login cannot be assigned while external POS is disabled for this organization.',
                UserLoginChannelService::MOBILE => 'Mobile users cannot be created while mobile app access is disabled for this organization.',
                UserLoginChannelService::MANAGER => 'Manager app users cannot be created while Centrix Manager is disabled for this organization.',
                UserLoginChannelService::BACKOFFICE => 'Backoffice login cannot be assigned while backoffice sales is disabled for this organization.',
                default => sprintf('%s login is not enabled for this organization.', $service->label($channel)),
            };

            throw ValidationException::withMessages([
                'login_channels' => [$message],
            ]);
        }
    }
}
