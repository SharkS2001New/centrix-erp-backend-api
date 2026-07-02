<?php

namespace App\Http\Middleware;

use App\Services\Erp\ErpContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureErpModule
{
    public function __construct(protected ErpContext $erp) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $gate = $request->user()
            ? $this->erp->gateForRequest($request)
            : $this->erp->gateForUser(null);

        $modules = array_values(array_filter(array_map('trim', explode(',', $module))));
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
                'module' => $module,
            ], 403);
        }

        $request->attributes->set('erp_gate', $gate);

        return $next($request);
    }
}
