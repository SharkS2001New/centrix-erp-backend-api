<?php

namespace App\Http\Middleware;

use App\Services\Erp\ErpContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureErpModule
{
    public function __construct(protected ErpContext $erp) {}

    /**
     * Laravel splits middleware parameters on commas, so erp.module:sales,payments
     * arrives as separate arguments — accept them via variadic + legacy comma strings.
     */
    public function handle(Request $request, Closure $next, string ...$moduleParams): Response
    {
        $gate = $request->user()
            ? $this->erp->gateForRequest($request)
            : $this->erp->gateForUser(null);

        $modules = [];
        foreach ($moduleParams as $param) {
            foreach (explode(',', $param) as $key) {
                $key = trim($key);
                if ($key !== '') {
                    $modules[] = $key;
                }
            }
        }
        $modules = array_values(array_unique($modules));

        $enabled = false;
        foreach ($modules as $key) {
            if ($gate->enabled($key)) {
                $enabled = true;
                break;
            }
        }

        if (! $enabled) {
            return response()->json([
                'message' => 'This feature is not enabled for your organization.',
                'module' => implode(',', $modules) ?: null,
            ], 403);
        }

        $request->attributes->set('erp_gate', $gate);

        return $next($request);
    }
}
