<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
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
        $user = $request->user();
        $orgId = (int) ($user?->organization_id ?? 0);

        if ($orgId <= 0) {
            return response()->json($this->buildCapabilitiesPayload($request));
        }

        $payload = OrganizationCache::remember(
            $orgId,
            'capabilities:user:'.(int) $user->id,
            (int) config('cache.organization_ttl', 3600),
            fn () => $this->buildCapabilitiesPayload($request),
        );

        return response()->json($this->applyRuntimeCapabilityFlags($request, $payload));
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
        $gate = $this->erp->gateForUser($user);

        $payload['is_super_admin'] = (bool) $user?->is_super_admin;
        $payload['is_admin'] = (bool) $user?->is_admin;
        $payload['access_scope'] = $user?->access_scope ?? 'org';
        $payload['branch_id'] = $user?->branch_id;
        $payload['allow_org_provisioning'] = (bool) $user?->is_super_admin
            && config('erp.allow_org_provisioning');
        $payload['workspaces'] = app(WorkspaceResolver::class)->availableForUser($user, $gate);

        $payload['platform_mpesa_stk_enabled'] = $gate->mpesaStkPlatformEnabled();
        $payload['platform_kra_integration_enabled'] = $gate->kraIntegrationPlatformEnabled();
        $payload['platform_ai_enabled'] = $gate->aiPlatformEnabled();

        $archive = app(LegacyArchiveReader::class);
        $org = $user?->organization_id ? Organization::query()->find($user->organization_id) : null;
        if ($org) {
            $payload['legacy_archive_enabled'] = $archive->isEnabled($org);
            $payload['legacy_archive_available'] = $archive->isAvailable($org);
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
}
