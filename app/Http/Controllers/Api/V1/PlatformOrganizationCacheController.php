<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
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

        if (! OrganizationCache::supportsTags()) {
            return response()->json([
                'message' => 'Organization cache flush requires Redis (CACHE_STORE=redis).',
                'organization_id' => $org->id,
                'cleared' => false,
            ], 422);
        }

        $cleared = OrganizationCache::flush((int) $org->id);

        return response()->json([
            'message' => $cleared
                ? 'Organization cache cleared.'
                : 'No tagged cache entries were found for this organization.',
            'organization_id' => $org->id,
            'company_code' => $org->company_code,
            'cleared' => $cleared,
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
