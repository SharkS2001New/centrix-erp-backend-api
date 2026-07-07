<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Mobile\ManagerAppModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureManagerAppEnabled
{
    public function __construct(
        protected ErpContext $erp,
        protected ManagerAppModuleAccessService $managerApp,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $gate = $this->erp->gateForUser($user);
        $this->managerApp->assertManagerAccess($user, $gate);

        return $next($request);
    }
}
