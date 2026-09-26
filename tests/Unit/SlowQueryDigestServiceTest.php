<?php

namespace Tests\Unit;

use App\Services\Platform\SlowQueryDigestService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SlowQueryDigestServiceTest extends TestCase
{
    public function test_noise_digests_are_rejected(): void
    {
        $service = app(SlowQueryDigestService::class);

        $this->assertTrue($service->isNoiseDigest("USE `pitchnewdb`"));
        $this->assertTrue($service->isNoiseDigest('COMMIT'));
        $this->assertTrue($service->isNoiseDigest("SELECT `option_value` FROM `wpa0_options`"));
        $this->assertTrue($service->isNoiseDigest('SELECT @@session.transaction_read_only'));
        $this->assertTrue($service->isNoiseDigest('OPTIMIZE TABLE `hikvision_agent_commands`'));
        $this->assertTrue($service->isNoiseDigest('TRUNCATE `centrix_erp`.`hikvision_agent_commands`'));
        $this->assertTrue($service->isNoiseDigest('DROP TABLE `centrix_erp`.`hikvision_agent_commands`'));
        $this->assertTrue($service->isNoiseDigest("SELECT * FROM `centrix_erp`.`hikvision_agent_commands`"));
        $this->assertTrue($service->isNoiseDigest(
            "SELECT SQL_NO_CACHE `id`, `organization_id` FROM `inventory_transactions`"
        ));
        $this->assertFalse($service->isNoiseDigest('SELECT * FROM `hikvision_agent_commands` WHERE id = ?'));
        $this->assertFalse($service->isNoiseDigest(
            'SELECT * FROM `inventory_transactions` WHERE `organization_id` = ? AND `created_at` >= ?'
        ));
    }

    #[DataProvider('centrixDigestProvider')]
    public function test_centrix_digest_filter(
        string $sql,
        string $rowSchema,
        bool $expected,
    ): void {
        $service = app(SlowQueryDigestService::class);
        $known = ['hikvision_agent_commands', 'sales', 'users', 'audit_logs'];

        $this->assertSame(
            $expected,
            $service->isCentrixDigest($sql, 'centrix_erp', $rowSchema, $known),
            $sql,
        );
    }

    public static function centrixDigestProvider(): array
    {
        return [
            'own schema optimize is noise' => [
                "OPTIMIZE TABLE `hikvision_agent_commands`",
                'centrix_erp',
                false,
            ],
            'qualified dump select is noise' => [
                "SELECT * FROM `centrix_erp`.`hikvision_agent_commands`",
                'centrix_erp',
                false,
            ],
            'qualified filtered select' => [
                "SELECT * FROM `centrix_erp`.`hikvision_agent_commands` WHERE `id` = ?",
                'centrix_erp',
                true,
            ],
            'foreign schema pitchnewdb' => [
                "SELECT * FROM `pitchnewdb`.`users`",
                'pitchnewdb',
                false,
            ],
            'null schema wordpress' => [
                "SELECT `option_value` FROM `wpa0_options` WHERE `option_name` = ?",
                '',
                false,
            ],
            'null schema centrix table' => [
                'SELECT * FROM `hikvision_agent_commands` WHERE `status` = ?',
                '',
                true,
            ],
            'null schema unrelated' => [
                'SELECT * FROM `random_other_table` WHERE id = ?',
                '',
                false,
            ],
            'table column not foreign db' => [
                'SELECT sales.organization_id FROM sales WHERE sales.id = ?',
                'centrix_erp',
                true,
            ],
            'use statement' => [
                "USE `centrix_erp`",
                'centrix_erp',
                false,
            ],
        ];
    }

    public function test_is_actually_slow_thresholds(): void
    {
        $service = app(SlowQueryDigestService::class);

        $this->assertTrue($service->isActuallySlow((object) [
            'avg_sec' => 0.03,
            'max_sec' => 0.01,
            'total_sec' => 0.1,
            'rows_examined' => 10,
        ]));
        $this->assertTrue($service->isActuallySlow((object) [
            'avg_sec' => 0.001,
            'max_sec' => 0.001,
            'total_sec' => 2.0,
            'rows_examined' => 10,
        ]));
        $this->assertFalse($service->isActuallySlow((object) [
            'avg_sec' => 0.005,
            'max_sec' => 0.01,
            'total_sec' => 0.2,
            'rows_examined' => 100,
        ]));
    }
}
