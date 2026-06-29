<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\Legacy\OrganizationLegacyArchiveService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLegacyArchiveEnabled
{
    public function __construct(
        protected OrganizationLegacyArchiveService $legacyArchive,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $org = Organization::query()->find($user->organization_id);
        if (! $org || ! $this->legacyArchive->isEnabled($org)) {
            return response()->json([
                'message' => 'Legacy archive is not enabled for this organization.',
                'code' => 'legacy_archive_disabled',
            ], 403);
        }

        return $next($request);
    }
}
