<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\Organization;
use App\Services\Erp\CapabilityGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WhatsAppPlatformPreviewService
{
    public function __construct(
        protected WhatsAppConfigResolver $configs,
        protected WhatsAppBotHandler $bot,
        protected WhatsAppProductCatalogService $catalog,
    ) {}

    /** @return array<string, mixed> */
    public function context(Organization $organization): array
    {
        $described = WhatsAppSettingsResolver::describeForOrganization($organization);
        $config = $this->configs->resolveForOrganizationPreview($organization);
        $botUser = $config ? $this->configs->botUser($config) : null;

        $customers = Customer::query()
            ->where('organization_id', $organization->id)
            ->orderBy('customer_name')
            ->limit(40)
            ->get(['customer_num', 'customer_name', 'phone_number', 'additional_phone', 'email'])
            ->map(fn (Customer $c) => [
                'customer_num' => $c->customer_num,
                'customer_name' => $c->customer_name,
                'phone' => $c->phone_number ?: $c->additional_phone,
            ])
            ->values()
            ->all();

        $ready = $config !== null && $botUser !== null;
        $issues = [];
        if (! ($described['platform_enabled'] ?? false)) {
            $issues[] = 'WhatsApp ordering is not enabled for this organization on the platform licence.';
        }
        if (! ($described['configured'] ?? false)) {
            $issues[] = 'Organization WhatsApp credentials are incomplete (phone number ID, access token, bot user, or enabled toggle). Soft preview may still work if a bot user is assigned.';
        }
        if (! $botUser) {
            $issues[] = 'Assign a bot user under the organization WhatsApp settings before testing.';
        }

        return [
            'organization_id' => $organization->id,
            'organization_name' => $organization->org_name,
            'company_code' => $organization->company_code,
            'platform_enabled' => (bool) ($described['platform_enabled'] ?? false),
            'configured' => (bool) ($described['configured'] ?? false),
            'preview_ready' => $ready,
            'issues' => $issues,
            'bot_user' => $described['bot_user'] ?? null,
            'display_phone' => $described['settings']['display_phone'] ?? null,
            'customers' => $customers,
            'dry_run' => true,
            'notice' => 'Test mode uses this organization’s real products and customers, but never places orders, reduces stock, creates handoffs, or sends WhatsApp messages.',
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, has_more: bool}
     */
    public function catalog(Organization $organization, ?string $customerNum, string $q = '', int $page = 1): array
    {
        $config = $this->configs->resolveForOrganizationPreview($organization);
        $botUser = $config ? $this->configs->botUser($config) : null;
        if (! $config || ! $botUser) {
            abort(422, 'Choose an organization with a WhatsApp bot user assigned to preview products.');
        }

        $customer = $this->resolveCustomer($organization, $customerNum, null);
        if (! $customer) {
            abort(422, 'Pick a customer from this organization to preview in-stock products for.');
        }

        $gate = (new CapabilityGate)->forOrganization($organization);
        $q = trim($q);
        if ($q !== '') {
            return $this->catalog->searchInStock($customer, $botUser, $gate, $q, $page);
        }

        return $this->catalog->browseInStock($customer, $botUser, $gate, $page);
    }

    /**
     * @param  array{session_id?: string|null, state?: string|null, payload?: array|null, customer_num?: string|null, phone?: string|null}  $session
     * @return array<string, mixed>
     */
    public function simulate(
        Organization $organization,
        string $message,
        ?string $customerNum = null,
        ?string $phone = null,
        ?array $session = null,
        ?int $actorUserId = null,
    ): array {
        $config = $this->configs->resolveForOrganizationPreview($organization);
        $botUser = $config ? $this->configs->botUser($config) : null;
        if (! $config || ! $botUser) {
            abort(422, 'Choose an organization with a WhatsApp bot user assigned before testing.');
        }

        $customer = $this->resolveCustomer($organization, $customerNum, $phone);
        $fromPhone = $phone
            ?: ($customer?->phone_number ?: $customer?->additional_phone)
            ?: '254700000000';

        $cacheKey = $this->sessionCacheKey(
            $actorUserId,
            $organization->id,
            $session['session_id'] ?? null,
            $customer?->customer_num,
            $fromPhone,
        );
        $cached = Cache::get($cacheKey);
        $sessionState = is_array($cached) ? $cached : (is_array($session) ? $session : null);

        $result = $this->bot->simulate(
            $config,
            (string) $fromPhone,
            $message,
            $customer,
            $sessionState,
        );

        $sessionId = (string) ($session['session_id'] ?? Str::uuid());
        $nextSession = [
            'session_id' => $sessionId,
            'state' => $result['state'],
            'payload' => $result['payload'],
            'customer_num' => $result['customer_num'],
            'phone' => $result['phone'],
        ];
        Cache::put($cacheKey, $nextSession, now()->addHours(2));

        return array_merge($result, [
            'session' => $nextSession,
            'organization_id' => $organization->id,
            'organization_name' => $organization->org_name,
            'notice' => 'Dry run only — no production data was changed and no WhatsApp message was sent.',
        ]);
    }

    public function resetSession(?int $actorUserId, int $organizationId, ?string $sessionId, ?string $customerNum, ?string $phone): void
    {
        Cache::forget($this->sessionCacheKey($actorUserId, $organizationId, $sessionId, $customerNum, $phone));
    }

    protected function resolveCustomer(
        Organization $organization,
        ?string $customerNum,
        ?string $phone,
    ): ?Customer {
        if ($customerNum) {
            return Customer::query()
                ->where('organization_id', $organization->id)
                ->where('customer_num', $customerNum)
                ->whereNull('deleted_at')
                ->first();
        }

        if ($phone) {
            return app(\App\Services\Customers\CustomerPhoneLookup::class)
                ->findByPhone($organization->id, $phone);
        }

        return null;
    }

    protected function sessionCacheKey(
        ?int $actorUserId,
        int $organizationId,
        ?string $sessionId,
        ?string $customerNum,
        ?string $phone,
    ): string {
        $sid = $sessionId ?: 'default';

        return 'wa_platform_preview:'
            .($actorUserId ?: 0).':'
            .$organizationId.':'
            .$sid.':'
            .($customerNum ?: '').':'
            .($phone ?: '');
    }
}
