<?php

namespace App\Services\WhatsApp;

use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\Cache;

class WhatsAppConfigResolver
{
    public function resolveForPhoneNumberId(?string $phoneNumberId): ?ResolvedWhatsAppConfig
    {
        $phoneNumberId = trim((string) $phoneNumberId);
        if ($phoneNumberId === '') {
            return $this->resolveDevFallback();
        }

        $row = WhatsappConfig::query()
            ->where('phone_number_id', $phoneNumberId)
            ->where('is_active', true)
            ->first();

        if (! $row) {
            return $this->resolveDevFallback($phoneNumberId);
        }

        $runtime = WhatsAppSettingsResolver::resolveRuntimeForOrganization(
            Organization::query()->findOrFail($row->organization_id),
        );

        if (! $runtime) {
            return null;
        }

        return new ResolvedWhatsAppConfig(
            organizationId: $runtime['organization_id'],
            branchId: $runtime['branch_id'],
            botUserId: $runtime['bot_user_id'],
            phoneNumberId: $runtime['phone_number_id'],
            accessToken: $runtime['access_token'],
            webhookVerifyToken: WhatsAppSettingsResolver::platformVerifyToken(),
            graphApiVersion: $runtime['graph_api_version'],
        );
    }

    public function botUser(ResolvedWhatsAppConfig $config): ?User
    {
        return Cache::remember(
            "whatsapp_bot_user:{$config->botUserId}",
            300,
            fn () => User::query()->find($config->botUserId),
        );
    }

    /**
     * Resolve org WhatsApp config for platform dry-run previews.
     * Uses live credentials when present; does not require Meta webhook routing.
     */
    public function resolveForOrganizationPreview(Organization $organization): ?ResolvedWhatsAppConfig
    {
        $runtime = WhatsAppSettingsResolver::resolveRuntimeForOrganization($organization);
        if ($runtime) {
            return new ResolvedWhatsAppConfig(
                organizationId: $runtime['organization_id'],
                branchId: $runtime['branch_id'],
                botUserId: $runtime['bot_user_id'],
                phoneNumberId: $runtime['phone_number_id'],
                accessToken: $runtime['access_token'],
                webhookVerifyToken: WhatsAppSettingsResolver::platformVerifyToken(),
                graphApiVersion: $runtime['graph_api_version'],
            );
        }

        // Soft preview: allow catalog/bot simulation when a bot user is assigned even if
        // the org has not finished enabling Meta credentials.
        $row = WhatsAppSettingsResolver::configRow($organization);
        $botUserId = (int) ($row?->bot_user_id ?? 0);
        if ($botUserId <= 0) {
            return null;
        }

        $botUser = User::query()->find($botUserId);
        if (! $botUser || (int) $botUser->organization_id !== (int) $organization->id) {
            return null;
        }

        $token = trim((string) ($row->access_token ?? ''));

        return new ResolvedWhatsAppConfig(
            organizationId: (int) $organization->id,
            branchId: $row->branch_id ? (int) $row->branch_id : ($botUser->branch_id ? (int) $botUser->branch_id : null),
            botUserId: $botUserId,
            phoneNumberId: trim((string) ($row->phone_number_id ?? '')) ?: 'preview',
            accessToken: $token !== '' ? $token : 'preview-dry-run',
            webhookVerifyToken: WhatsAppSettingsResolver::platformVerifyToken(),
            graphApiVersion: (string) ($row->graph_api_version ?? config('whatsapp.graph_api_version', 'v21.0')),
        );
    }

    protected function resolveDevFallback(?string $phoneNumberId = null): ?ResolvedWhatsAppConfig
    {
        if (! app()->environment('local', 'testing')) {
            return null;
        }

        $orgId = (int) config('whatsapp.organization_id');
        $botUserId = (int) config('whatsapp.bot_user_id');
        $token = (string) config('whatsapp.access_token');
        $configuredPhoneId = (string) config('whatsapp.phone_number_id');

        if ($orgId <= 0 || $botUserId <= 0) {
            return null;
        }

        if ($phoneNumberId && $configuredPhoneId && $phoneNumberId !== $configuredPhoneId) {
            return null;
        }

        $botUser = User::query()->find($botUserId);
        if (! $botUser || (int) $botUser->organization_id !== $orgId) {
            return null;
        }

        return new ResolvedWhatsAppConfig(
            organizationId: $orgId,
            branchId: $botUser->branch_id ? (int) $botUser->branch_id : null,
            botUserId: $botUserId,
            phoneNumberId: $configuredPhoneId ?: $phoneNumberId,
            accessToken: $token,
            webhookVerifyToken: WhatsAppSettingsResolver::platformVerifyToken(),
            graphApiVersion: (string) config('whatsapp.graph_api_version', 'v21.0'),
        );
    }
}
