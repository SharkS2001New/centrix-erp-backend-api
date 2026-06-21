<?php

namespace App\Http\Middleware;

use App\Services\Erp\ErpContext;
use App\Services\Erp\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureReportModule
{
    public function __construct(protected ErpContext $erp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $gate = $request->user()
            ? $this->erp->gateForRequest($request)
            : $this->erp->gateForUser(null);
        $request->attributes->set('erp_gate', $gate);

        $slug = $this->resolveReportSlug($request);
        if ($slug === null) {
            return $next($request);
        }

        $accessModules = ModuleRegistry::reportAccessModulesForSlug($slug);
        if ($accessModules !== [] && ! collect($accessModules)->contains(fn (string $key) => $gate->reportModuleEnabled($key))) {
            return response()->json([
                'message' => 'This report is not enabled for your organization.',
                'module' => $accessModules[0],
            ], 403);
        }

        return $next($request);
    }

    protected function resolveReportSlug(Request $request): ?string
    {
        $path = trim($request->path(), '/');
        if (! str_starts_with($path, 'api/v1/reports/')) {
            return null;
        }

        $suffix = substr($path, strlen('api/v1/reports/'));
        if ($suffix === '' || $suffix === 'dashboard' || $suffix === 'builder') {
            return null;
        }

        if (str_starts_with($suffix, 'builder/')) {
            return null;
        }

        return explode('/', $suffix)[0] ?: null;
    }
}
