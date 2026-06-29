<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\PasswordExpiryService;
use App\Services\Auth\UserPermissionService;
use App\Services\Cache\OrganizationCache;
use App\Services\Erp\ErpContext;
use App\Services\Erp\WorkspaceResolver;
use App\Services\Legacy\LegacyArchiveReader;
use App\Services\Legacy\OrganizationLegacyArchiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ErpCapabilitiesController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    /** GET /api/v1/erp/capabilities — what this tenant can use */
    public function show(Request $request)
    {
        return response()->json($this->resolveForRequest($request));
    }

    /** @return array<string, mixed> */
    public function resolveForRequest(Request $request): array
    {
        $user = $request->user();
        $orgId = (int) ($user?->organization_id ?? 0);

        if ($orgId <= 0) {
            return $this->applyRuntimeCapabilityFlags($request, $this->buildCapabilitiesPayload($request));
        }

        $payload = OrganizationCache::remember(
            $orgId,
            OrganizationCache::capabilitiesUserKey($orgId, (int) $user->id),
            (int) config('cache.organization_ttl', 3600),
            fn () => $this->buildCapabilitiesPayload($request),
        );

        return $this->applyRuntimeCapabilityFlags($request, $payload);
    }

    /** @return array<string, mixed> */
    public function resolveForUser(User $user): array
    {
        $request = Request::create('/api/v1/erp/capabilities', 'GET');
        $request->setUserResolver(fn () => $user);

        return $this->resolveForRequest($request);
    }

    /** GET /api/v1/erp/profiles — deployment profile definitions (for admin UI) */
    public function profiles()
    {
        $payload = Cache::remember('erp:profiles:v1', 86400, fn () => [
            'profiles' => config('erp.profiles'),
            'modules' => config('erp.modules'),
        ]);

        return response()->json($payload);
    }

    /** @return array<string, mixed> */
    protected function buildCapabilitiesPayload(Request $request): array
    {
        $gate = $this->erp->gateForUser($request->user());
        $user = $request->user();

        return array_merge($gate->toArray(), [
            'is_super_admin' => (bool) $user?->is_super_admin,
            'is_admin' => (bool) $user?->is_admin,
            'access_scope' => $user?->access_scope ?? 'org',
            'branch_id' => $user?->branch_id,
            'permissions' => $user
                ? app(UserPermissionService::class)->permissionMapForUser($user, $gate)
                : [],
            'allow_org_provisioning' => (bool) $user?->is_super_admin
                && config('erp.allow_org_provisioning'),
            'workspaces' => app(WorkspaceResolver::class)->availableForUser($user, $gate),
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    protected function applyRuntimeCapabilityFlags(Request $request, array $payload): array
    {
        $user = $request->user();
        if (! $user) {
            return $payload;
        }

        $gate = $this->erp->gateForUser($user);
        $org = $gate->organization();

        // Lightweight fields that may change without busting the org capabilities cache.
        $payload['is_super_admin'] = (bool) $user->is_super_admin;
        $payload['is_admin'] = (bool) $user->is_admin;
        $payload['access_scope'] = $user->access_scope ?? 'org';
        $payload['branch_id'] = $user->branch_id;
        $payload['allow_org_provisioning'] = (bool) $user->is_super_admin
            && config('erp.allow_org_provisioning');
        $payload['password_expiry'] = app(PasswordExpiryService::class)->statusForUser($user);

        $payload['platform_mpesa_stk_enabled'] = $gate->mpesaStkPlatformEnabled();
        $payload['platform_kra_integration_enabled'] = $gate->kraIntegrationPlatformEnabled();
        $payload['platform_ai_enabled'] = $gate->aiPlatformEnabled();

        $archive = app(LegacyArchiveReader::class);
        if ($org) {
            $payload['legacy_archive_enabled'] = $archive->isEnabled($org);
            $payload['legacy_archive_available'] = $this->legacyArchiveConnectable($org, $archive);
            $payload['legacy_archive_cutover_date'] = $archive->cutoverDate($org)?->toDateString();
            $payload['legacy_archive_label'] = app(OrganizationLegacyArchiveService::class)->forOrganization($org)['label'] ?? 'LightStores archive';
        } else {
            $payload['legacy_archive_enabled'] = false;
            $payload['legacy_archive_available'] = false;
            $payload['legacy_archive_cutover_date'] = null;
            $payload['legacy_archive_label'] = null;
        }

        if (isset($payload['module_settings']) && is_array($payload['module_settings'])) {
            $payload['module_settings'] = $gate->maskPlatformDisabledModuleSettings($payload['module_settings']);
        }

        return $payload;
    }

    /** Ping only — avoid counting legacy archive tables on login/capabilities. */
    protected function legacyArchiveConnectable(Organization $org, LegacyArchiveReader $archive): bool
    {
        if (! app(OrganizationLegacyArchiveService::class)->isConfigured($org)) {
            return false;
        }

        $cacheKey = OrganizationCache::tag($org->id).':legacy_archive:connectable';

        return Cache::remember($cacheKey, 300, fn () => $archive->isAvailable($org));
    }
}
