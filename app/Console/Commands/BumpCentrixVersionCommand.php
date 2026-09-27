<?php

namespace App\Console\Commands;

use App\Support\CentrixVersion;
use Illuminate\Console\Command;

class BumpCentrixVersionCommand extends Command
{
    protected $signature = 'centrix:bump-version
                            {--part=patch : major, minor, or patch}
                            {--set= : Set an exact X.Y.Z instead of bumping}';

    protected $description = 'Bump or set the Centrix product VERSION (semver).';

    public function handle(): int
    {
        $set = trim((string) $this->option('set'));
        if ($set !== '') {
            CentrixVersion::write($set);
            $this->info('Centrix version set to '.CentrixVersion::label());

            return self::SUCCESS;
        }

        $before = CentrixVersion::current();
        $after = CentrixVersion::bump((string) $this->option('part'));
        $this->info("Centrix version {$before} → {$after}");

        return self::SUCCESS;
    }
}
