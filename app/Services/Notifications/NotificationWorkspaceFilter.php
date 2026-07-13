<?php

namespace App\Services\Notifications;

use Illuminate\Database\Eloquent\Builder;

class NotificationWorkspaceFilter
{
    /** @var array<string, list<string>> */
    private const MODULES_BY_WORKSPACE = [
        'backoffice' => ['sales', 'purchasing', 'inventory'],
        'accounting' => ['accounting'],
        'hr' => ['hr_payroll'],
        'admin' => ['admin'],
        'pos' => ['sales'],
        'distribution' => [],
    ];

    /** @var array<string, list<string>> */
    private const PREFIXES_BY_WORKSPACE = [
        'pos' => ['/pos', '/sales/pos'],
        'backoffice' => [
            '/dashboard',
            '/sales',
            '/inventory',
            '/products',
            '/categories',
            '/sub-categories',
            '/uoms',
            '/retail-package-settings',
            '/vats',
            '/price-history',
            '/customers',
            '/suppliers',
            '/lpo',
            '/purchases',
            '/expenses',
            '/routes',
            '/till-management',
            '/fulfillment/routes',
            '/sales/picking-lists',
            '/fulfillment/loading-lists',
        ],
        'admin' => ['/admin', '/vats'],
        'accounting' => ['/accounting', '/expenses', '/finance'],
        'hr' => ['/hr', '/employees'],
        'distribution' => ['/fulfillment', '/dispatch-trips', '/sales/orders/'],
    ];

    public function apply(Builder $query, ?string $workspace): void
    {
        $workspace = strtolower(trim((string) $workspace));
        if ($workspace === '') {
            return;
        }

        $modules = self::MODULES_BY_WORKSPACE[$workspace] ?? [];
        $prefixes = self::PREFIXES_BY_WORKSPACE[$workspace] ?? [];

        $query->where(function (Builder $outer) use ($modules, $prefixes) {
            $matched = false;

            if ($modules !== []) {
                $outer->whereHas('actionRequest', function (Builder $request) use ($modules) {
                    $request->whereIn('module', $modules);
                });
                $matched = true;
            }

            foreach ($prefixes as $prefix) {
                if ($matched) {
                    $outer->orWhere('action_url', 'like', $prefix.'%');
                } else {
                    $outer->where('action_url', 'like', $prefix.'%');
                    $matched = true;
                }
            }

            if (! $matched) {
                $outer->whereRaw('1 = 0');
            }
        });
    }
}
