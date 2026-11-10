<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\ApplicationProvisioner;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HotelAsMobileExpensesAccessTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_hotel_as_mobile_can_list_expenses_without_accounting_app(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $modules = app(ApplicationProvisioner::class)->enabledModulesFromApplications([
            'hotel_bar_pos' => true,
            'hospitality_backoffice' => true,
            'accounting' => false,
            'hr' => false,
            'admin' => true,
        ]);
        $this->assertFalse((bool) ($modules['accounting'] ?? false));
        $this->assertTrue((bool) ($modules['hospitality.bar_pos'] ?? false));

        $org->forceFill([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => $modules,
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $admin = User::where('username', 'admin')->firstOrFail();
        $user = User::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'hotel_exp_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Hotel Expenses User',
            'is_admin' => true,
            'access_scope' => 'org',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);

        $issued = $user->createToken('AS_EXPENSES', ['*']);
        $issued->accessToken->forceFill([
            'organization_id' => $user->organization_id,
            'login_channel' => 'centrix_as_mobile',
        ])->save();

        // CentrixAsMobile Expenses Report: /mobile/expenses then /expenses.
        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v1/expenses?from_date=2026-11-01&to_date=2026-11-10&per_page=10')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v1/mobile/expenses?from_date=2026-11-01&to_date=2026-11-10')
            ->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJsonMissingPath('errors.feature');
    }
}
