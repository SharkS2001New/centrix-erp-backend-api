<?php

namespace App\Console\Commands;

use App\Services\Erp\PermissionMatrixService;
use Illuminate\Console\Command;

class ExportPermissionRegistryCommand extends Command
{
    protected $signature = 'erp:export-permission-registry';

    protected $description = 'Output all feature permission codes from permission_registry.php as JSON';

    public function handle(): int
    {
        PermissionMatrixService::ensure();

        $codes = PermissionMatrixService::allRegistryCodes();
        sort($codes);

        $this->line(json_encode($codes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
