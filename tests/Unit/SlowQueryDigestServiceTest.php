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
        $this->assertFalse($service->isNoiseDigest('SELECT * FROM `hikvision_agent_commands` WHERE id = ?'));
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
            'own schema optimize' => [
                "OPTIMIZE TABLE `hikvision_agent_commands`",
                'centrix_erp',
                true,
            ],
            'qualified own schema' => [
                "SELECT * FROM `centrix_erp`.`hikvision_agent_commands`",
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
                'SELECT * FROM `random_other_table`',
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
}
