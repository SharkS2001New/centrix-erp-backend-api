<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Organization\OrganizationCompanyCodeService;
use Illuminate\Console\Command;

class RenameOrganizationCompanyCodeCommand extends Command
{
    protected $signature = 'org:rename-company-code
                            {organization : Organization ID or current company code}
                            {code : New company code, e.g. MOON}';

    protected $description = 'Rename a tenant company code and keep the old code as a login alias';

    public function handle(OrganizationCompanyCodeService $service): int
    {
        $target = trim((string) $this->argument('organization'));
        $newCode = strtoupper(trim((string) $this->argument('code')));

        $org = is_numeric($target)
            ? Organization::query()->find($target)
            : Organization::findByCompanyCodeIdentifier($target);

        if (! $org) {
            $this->error("Organization not found for [{$target}].");

            return self::FAILURE;
        }

        $oldCode = (string) $org->company_code;
        $org = $service->rename($org, $newCode);

        $this->info("Organization #{$org->id} ({$org->org_name})");
        $this->line("  Primary code: {$org->company_code}");
        $this->line('  Aliases: '.implode(', ', $org->company_code_aliases ?? []) ?: '(none)');
        if (filled($org->module_settings['legacy_archive']['legacy_company_code'] ?? null)) {
            $this->line('  Legacy archive company code: '.$org->module_settings['legacy_archive']['legacy_company_code']);
        }
        $this->newLine();
        $this->comment("Users can sign in with {$org->company_code} or the old code {$oldCode}.");

        return self::SUCCESS;
    }
}
