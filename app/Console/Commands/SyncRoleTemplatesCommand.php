<?php

namespace App\Console\Commands;

use App\Services\Auth\RoleTemplateService;
use Illuminate\Console\Command;

class SyncRoleTemplatesCommand extends Command
{
    protected $signature = 'erp:sync-role-templates';

    protected $description = 'Ensure production role templates and permissions exist (idempotent; safe to run after migrate)';

    public function handle(RoleTemplateService $roles): int
    {
        $roles->ensureAllRoles();

        $definitions = config('role_templates.roles', []);
        $this->info('Synced '.count($definitions).' production role template(s).');

        return self::SUCCESS;
    }
}
