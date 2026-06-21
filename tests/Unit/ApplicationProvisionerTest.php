<?php

namespace Tests\Unit;

use App\Services\Erp\ApplicationProvisioner;
use App\Services\Erp\ModuleRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationProvisionerTest extends TestCase
{
    protected ApplicationProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisioner = new ApplicationProvisioner;
    }

    public function test_options_payload_lists_six_applications(): void
    {
        $options = $this->provisioner->optionsPayload();

        $this->assertCount(6, $options);
        $this->assertSame(
            ['pos', 'backoffice', 'distribution', 'accounting', 'hr', 'admin'],
            array_column($options, 'id'),
        );
    }

    public function test_enabled_modules_from_applications_enables_pos_stack(): void
    {
        $modules = $this->provisioner->enabledModulesFromApplications([
            'pos' => true,
            'backoffice' => false,
            'distribution' => false,
            'accounting' => false,
            'hr' => false,
            'admin' => true,
        ]);

        $this->assertTrue($modules['sales.pos']);
        $this->assertTrue($modules['sales.backend']);
        $this->assertTrue($modules['inventory']);
        $this->assertTrue($modules['admin']);
        $this->assertFalse($modules['accounting']);
        $this->assertFalse($modules['distribution']);
    }

    public function test_round_trip_applications_and_modules(): void
    {
        $applications = [
            'pos' => false,
            'backoffice' => true,
            'distribution' => false,
            'accounting' => true,
            'hr' => true,
            'admin' => false,
        ];

        $modules = $this->provisioner->enabledModulesFromApplications($applications);
        $roundTrip = $this->provisioner->applicationsFromEnabledModules($modules);

        $this->assertTrue($roundTrip['backoffice']);
        $this->assertTrue($roundTrip['accounting']);
        $this->assertTrue($roundTrip['hr']);
        $this->assertFalse($roundTrip['pos']);
        $this->assertFalse($roundTrip['admin']);
    }

    public function test_distribution_requires_mobile_orders(): void
    {
        $modules = $this->provisioner->enabledModulesFromApplications([
            'pos' => false,
            'backoffice' => true,
            'distribution' => true,
            'accounting' => false,
            'hr' => false,
            'admin' => false,
        ], mobileOrdersEnabled: false);

        $this->assertFalse($modules['distribution']);
    }

    public function test_distribution_enables_mobile_sales_when_allowed(): void
    {
        $modules = $this->provisioner->enabledModulesFromApplications([
            'pos' => false,
            'backoffice' => true,
            'distribution' => true,
            'accounting' => false,
            'hr' => false,
            'admin' => false,
        ], mobileOrdersEnabled: true);

        $this->assertTrue($modules['distribution']);
        $this->assertTrue($modules['sales.mobile']);
    }

    public function test_unknown_application_key_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->provisioner->sanitizeApplications([
            'pos' => true,
            'backoffice_finance_reports' => true,
        ]);
    }

    #[DataProvider('profileApplicationProvider')]
    public function test_profile_modules_map_to_expected_applications(string $profile, array $expected): void
    {
        $profileModules = config("erp.profiles.{$profile}.modules", []);
        $applications = $this->provisioner->applicationsFromProfileModules($profileModules);

        foreach ($expected as $appId => $enabled) {
            $this->assertSame($enabled, $applications[$appId] ?? false, "profile {$profile} app {$appId}");
        }
    }

    public static function profileApplicationProvider(): array
    {
        return [
            'small_shop' => ['small_shop', [
                'pos' => false,
                'backoffice' => true,
                'distribution' => false,
                'accounting' => true,
                'hr' => false,
                'admin' => true,
            ]],
            'wholesale_retail' => ['wholesale_retail', [
                'pos' => true,
                'backoffice' => true,
                'distribution' => false,
                'accounting' => true,
                'hr' => true,
                'admin' => true,
            ]],
        ];
    }

    public function test_cascade_never_persists_config_only_keys(): void
    {
        $modules = $this->provisioner->enabledModulesFromApplications([
            'pos' => true,
            'backoffice' => true,
            'distribution' => false,
            'accounting' => false,
            'hr' => false,
            'admin' => true,
        ]);

        foreach (ModuleRegistry::configOnlyKeys() as $key) {
            $this->assertArrayNotHasKey($key, $modules);
        }
    }
}
