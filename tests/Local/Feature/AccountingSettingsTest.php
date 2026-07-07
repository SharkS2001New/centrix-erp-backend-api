<?php


/**
 * Local-only tests — excluded from CI / default `composer test`.
 * Run manually: composer test:local
 */

namespace Tests\Local\Feature;

use App\Models\AccountingConnection;
use App\Models\AccountingExportQueue;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AccountingSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());
    }

    public function test_admin_can_read_and_update_auto_post_settings(): void
    {
        $this->getJson('/api/v1/accounting/settings')
            ->assertOk()
            ->assertJsonStructure(['accounting' => ['auto_post_sales', 'auto_post_expenses']]);

        $this->patchJson('/api/v1/accounting/settings', [
            'auto_post_expenses' => false,
            'auto_post_payroll' => false,
        ])->assertOk()
            ->assertJsonPath('accounting.auto_post_expenses', false)
            ->assertJsonPath('accounting.auto_post_payroll', false);
    }

    public function test_admin_can_seed_standard_chart_of_accounts(): void
    {
        $this->postJson('/api/v1/accounting/seed-chart-of-accounts')
            ->assertCreated()
            ->assertJsonPath('chart_seeded', true);
    }
}
