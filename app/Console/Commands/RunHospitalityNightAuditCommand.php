<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Services\Hospitality\HospitalityNightAuditService;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class RunHospitalityNightAuditCommand extends Command
{
    protected $signature = 'erp:run-hospitality-night-audit
                            {--date= : Business date (Y-m-d). Defaults to yesterday.}
                            {--organization= : Limit to one organization id}';

    protected $description = 'Auto-run hospitality night audit for orgs with night_audit enabled';

    public function handle(HospitalityNightAuditService $audits): int
    {
        $date = $this->option('date')
            ? (string) $this->option('date')
            : now()->subDay()->toDateString();
        $onlyOrg = $this->option('organization') ? (int) $this->option('organization') : null;

        $query = Organization::query()->orderBy('id');
        if ($onlyOrg) {
            $query->where('id', $onlyOrg);
        }

        $ran = 0;
        $skipped = 0;
        $failed = 0;

        $query->chunkById(50, function ($orgs) use ($audits, $date, &$ran, &$skipped, &$failed) {
            foreach ($orgs as $org) {
                /** @var Organization $org */
                if (! HospitalityServices::enabled($org, 'night_audit')) {
                    $skipped++;
                    continue;
                }
                $actor = User::query()
                    ->where('organization_id', $org->id)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->first();
                if (! $actor) {
                    $this->warn("Org {$org->id}: no active user to attribute night audit.");
                    $skipped++;
                    continue;
                }
                try {
                    $result = $audits->run($org, $actor, $date);
                    $ran++;
                    $this->info(sprintf(
                        'Org %d: posted %d rooms · %s',
                        $org->id,
                        (int) ($result['rooms_posted'] ?? 0),
                        number_format((float) ($result['amount_posted'] ?? 0), 2),
                    ));
                } catch (ValidationException $e) {
                    $skipped++;
                    $msg = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                    $this->line("Org {$org->id}: skipped — {$msg}");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("Org {$org->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Night audit complete. ran={$ran} skipped={$skipped} failed={$failed} date={$date}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
