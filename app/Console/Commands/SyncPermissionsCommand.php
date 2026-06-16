<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'erp:permissions-sync
                            {--grant-admin : Grant every permission to Administrator roles}';

    protected $description = 'Sync permission registry and route capability codes into the permissions table';

    public function handle(): int
    {
        PermissionMatrixService::ensure();

        $registryCount = count(PermissionMatrixService::allRegistryCodes());
        $capabilityCount = count(config('permissions', []));
        $total = Permission::query()->count();

        $this->info("Synced {$total} permissions ({$registryCount} feature + {$capabilityCount} route capabilities).");

        if ($this->option('grant-admin')) {
            $adminRoles = Role::query()
                ->whereIn('role_name', ['Administrator', 'Admin'])
                ->pluck('id');

            $permissionIds = Permission::query()->pluck('id');
            $granted = 0;

            foreach ($adminRoles as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    $inserted = DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                    $granted += $inserted;
                }
            }

            $this->info("Granted {$granted} new role-permission links to administrator roles.");
        }

        $this->line('Open Admin → Roles & permissions to review the updated matrix and re-save custom roles if needed.');

        return self::SUCCESS;
    }
}
