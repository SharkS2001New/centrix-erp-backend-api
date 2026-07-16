<?php

namespace App\Http\Middleware;

use App\Services\Erp\ErpContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAnyErpModule
{
    public function __construct(protected ErpContext $erp) {}

    /**
     * Laravel splits middleware parameters on commas, so accept variadic module keys.
     */
    public function handle(Request $request, Closure $next, string ...$moduleParams): Response
    {
        $gate = $request->user()
            ? $this->erp->gateForRequest($request)
            : $this->erp->gateForUser(null);

        $keys = [];
        foreach ($moduleParams as $param) {
            foreach (explode(',', $param) as $module) {
                $module = trim($module);
                if ($module !== '') {
                    $keys[] = $module;
                }
            }
        }
        $keys = array_values(array_unique($keys));

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
