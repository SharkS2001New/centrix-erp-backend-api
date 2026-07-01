<?php

namespace App\Support;

use Illuminate\Http\Request;
use Throwable;

class ApiErrorPresenter
{
    public static function shouldExposeDetail(?object $user): bool
    {
        return (bool) ($user?->is_super_admin);
    }

    public static function moduleLabelForRequest(Request $request): string
    {
        return self::moduleLabelForPath($request->path());
    }

    public static function moduleLabelForPath(string $path): string
    {
        $normalized = strtolower(trim($path, '/'));
        if (str_starts_with($normalized, 'api/v1/')) {
            $normalized = substr($normalized, 7);
        } elseif (str_starts_with($normalized, 'api/')) {
            $normalized = substr($normalized, 4);
        }

        $segments = array_values(array_filter(explode('/', $normalized)));
        $first = $segments[0] ?? 'application';
        $second = $segments[1] ?? null;

        if ($first === 'erp' && $second === 'settings') {
            return 'Settings';
        }

        return match ($first) {
            'organizations' => 'Platform',
            'sales' => 'Sales',
            'inventory' => 'Inventory',
            'fulfillment', 'dispatch', 'trips', 'routes', 'drivers', 'vehicles' => 'Distribution',
            'hr', 'employees', 'payroll', 'attendance' => 'Human resources',
            'accounting' => 'Accounting',
            'admin' => 'Administration',
            'products' => 'Products',
            'customers' => 'Customers',
            'suppliers' => 'Suppliers',
            'lpo-mst', 'lpo' => 'Purchasing',
            'expenses' => 'Expenses',
            'reports' => 'Reports',
            'users' => 'Users',
            'branches' => 'Branches',
            'erp' => 'Settings',
            default => ucwords(str_replace(['-', '_'], ' ', $first)),
        };
    }

    public static function userMessage(Throwable $e, Request $request, ?object $user): array
    {
        $module = self::moduleLabelForRequest($request);
        $detail = trim($e->getMessage()) !== '' ? trim($e->getMessage()) : class_basename($e);
        $location = $e->getFile()
            ? sprintf(' (%s:%d)', basename($e->getFile()), $e->getLine())
            : '';

        if (self::shouldExposeDetail($user)) {
            return [
                'message' => $detail,
                'detail' => $detail.$location,
                'code' => 'server_error',
                'module' => $module,
                'expose_detail' => true,
            ];
        }

        return [
            'message' => "An error occurred in {$module}. Please report this to your system administrator.",
            'code' => 'server_error',
            'module' => $module,
            'expose_detail' => false,
        ];
    }
}
