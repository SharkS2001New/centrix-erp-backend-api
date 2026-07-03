<?php

namespace App\Http\Middleware;

use App\Services\Erp\ErpContext;
use App\Services\Mobile\MobileAppModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileSalesAppEnabled
{
    public function __construct(
        protected ErpContext $erp,
        protected MobileAppModuleAccessService $mobileApp,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $gate = $this->erp->gateForRequest($request);
        $this->mobileApp->assertSalesAccess($user, $gate);

        return $next($request);
    }
}
