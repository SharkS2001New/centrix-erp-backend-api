<?php

namespace App\Services\Erp;

use App\Models\Permission;

class PermissionMatrixService
{
    /** @return array<string, string> module key => display label */
    public static function modules(): array
    {
        return [
            'dashboard' => 'Dashboard',
            'sales' => 'Sales',
            'inventory' => 'Inventory',
            'purchasing' => 'Procurement',
            'accounting' => 'Finance',
            'hr' => 'HR & Payroll',
            'admin' => 'Administration',
            'catalogue' => 'Products',
            'reports' => 'Reports',
            'payments' => 'Payments',
            'pos' => 'Point of sale',
        ];
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return ['view', 'create', 'edit', 'delete', 'approve'];
    }

    public static function ensure(): void
    {
        foreach (self::modules() as $module => $label) {
            foreach (self::actions() as $action) {
                $code = "{$module}.{$action}";
                $name = "{$label} — ".ucfirst($action);
                Permission::firstOrCreate(
                    ['permission_code' => $code],
                    ['permission_name' => $name, 'module' => $module]
                );
            }
        }
    }
}
