<?php

namespace App\Services\Legacy;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LegacyArchiveConnectionManager
{
    public function __construct(
        protected OrganizationLegacyArchiveService $settings,
    ) {}

    public function connectionNameForOrganization(Organization $org): string
    {
        return 'legacy_org_'.$org->id;
    }

    /**
     * Register (or refresh) a per-organization legacy MySQL connection and return its name.
     */
    public function configureForOrganization(Organization $org): string
    {
        $config = $this->settings->forOrganization($org);

        if (! $this->settings->isConfigured($org)) {
            throw new RuntimeException('Legacy archive is not enabled or configured for this organization.');
        }

        $template = config('database.connections.legacy', config('database.connections.mysql'));
        $name = $this->connectionNameForOrganization($org);

        $connection = array_merge($template, [
            'database' => (string) $config['database'],
            'host' => $config['host'] ?: ($template['host'] ?? '127.0.0.1'),
            'port' => $config['port'] ?: ($template['port'] ?? '3306'),
            'username' => $config['username'] ?: ($template['username'] ?? 'root'),
            'password' => $config['password'] ?? ($template['password'] ?? ''),
        ]);

        config(['database.connections.'.$name => $connection]);
        DB::purge($name);

        return $name;
    }

    public function isReachable(Organization $org): bool
    {
        if (! $this->settings->isConfigured($org)) {
            return false;
        }

        try {
            $name = $this->configureForOrganization($org);
            DB::connection($name)->select('SELECT 1');

            $inspect = app(LightStoresArchiveDatabaseService::class)->inspect($name);

            return $inspect['missing'] === [];
        } catch (\Throwable) {
            return false;
        }
    }
}
