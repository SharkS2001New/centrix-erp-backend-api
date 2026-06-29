<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Cache\CapabilitiesCacheInvalidator;
use App\Services\Cache\OrganizationCache;
use Illuminate\Http\Request;

class PlatformOrganizationCacheController extends Controller
{
    /** GET /api/v1/admin/organizations/{organization}/cache */
    public function show(int $organization)
    {
        $org = $this->findTenantOrganization($organization);

        return response()->json([
            'organization_id' => $org->id,
            'company_code' => $org->company_code,
            'redis_tags_supported' => OrganizationCache::supportsTags(),
            'cache_store' => config('cache.default'),
            'cacheable' => OrganizationCache::cacheableKeys(),
        ]);
    }

    /** POST /api/v1/admin/organizations/{organization}/cache/clear */
    public function clear(Request $request, int $organization)
    {
        $org = $this->findTenantOrganization($organization);

        CapabilitiesCacheInvalidator::forOrganization((int) $org->id);

        return response()->json([
            'message' => 'Organization capabilities cache cleared.',
            'organization_id' => $org->id,
            'company_code' => $org->company_code,
            'cleared' => true,
            'capabilities_version' => OrganizationCache::capabilitiesVersion((int) $org->id),
            'redis_tags_supported' => OrganizationCache::supportsTags(),
        ]);
    }

    protected function findTenantOrganization(int $organizationId): Organization
    {
        $platformCode = config('erp.platform_company_code', 'PLATFORM');

        return Organization::query()
            ->where('id', $organizationId)
            ->where('company_code', '!=', $platformCode)
            ->firstOrFail();
    }
}
