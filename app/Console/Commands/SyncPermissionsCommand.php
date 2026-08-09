<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'erp:permissions-sync
                            {--grant-admin : Grant every industry-scoped permission to Administrator roles}';

    protected $description = 'Sync permission registry and route capability codes into the permissions table';

    public function handle(): int
    {
        PermissionMatrixService::ensure();

        $registryCount = count(PermissionMatrixService::allRegistryCodes());
        $capabilityCount = count(config('permissions', []));
        $total = Permission::query()->count();

        $this->info("Synced {$total} permissions ({$registryCount} feature + {$capabilityCount} route capabilities).");

        if ($this->option('grant-admin')) {
            // ensure() already attaches industry catalogs; option keeps the explicit CLI path.
            PermissionMatrixService::ensureAdministratorIndustryCatalogPermissions();
            $this->info('Granted industry-catalog permissions to administrator roles (all industries).');
        }

        $this->line('Open Admin → Roles & permissions to review the updated matrix and re-save custom roles if needed.');

        return self::SUCCESS;
    }
}
