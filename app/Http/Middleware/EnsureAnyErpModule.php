<?php

namespace App\Http\Middleware;

use App\Services\Erp\ErpContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAnyErpModule
{
    public function __construct(protected ErpContext $erp) {}

    public function handle(Request $request, Closure $next, string $modules): Response
    {
        $gate = $request->user()
            ? $this->erp->gateForRequest($request)
            : $this->erp->gateForUser(null);
        $keys = array_values(array_filter(array_map('trim', explode(',', $modules))));

        foreach ($keys as $module) {
            if ($gate->enabled($module)) {
                $request->attributes->set('erp_gate', $gate);

                return $next($request);
            }
        }

        return response()->json([
            'message' => 'This feature is not enabled for your organization.',
            'modules' => $keys,
        ], 403);
    }
}
