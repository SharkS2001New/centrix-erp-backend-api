<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Investor;
use App\Models\InvestorContribution;
use App\Models\InvestorProductBatch;
use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class InvestorFlowTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);

        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);

        PermissionMatrixService::ensure();

        $org = Organization::findOrFail($this->user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['investors'] = true;
        $modules['investors.reports'] = true;
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $settings['investors'] = array_merge($settings['investors'] ?? [], [
            'enable_investors' => true,
        ]);
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);
    }

    public function test_create_investor_cash_contribution_and_stock_batch(): void
    {
        $create = $this->postJson('/api/v1/investors', [
            'investor_name' => 'Hasco Group Limited',
            'contact_person' => 'Accounts',
            'phone' => '0700000000',
        ])->assertCreated();

        $investorId = (int) $create->json('id');
        $this->assertNotEmpty($create->json('investor_code'));

        $this->postJson("/api/v1/investors/{$investorId}/contributions", [
            'contribution_type' => 'cash',
            'contribution_date' => now()->toDateString(),
            'amount' => 50000,
            'notes' => 'Bank deposit',
        ])->assertCreated();

        $stock = $this->postJson("/api/v1/investors/{$investorId}/contributions", [
            'contribution_type' => 'stock',
            'contribution_date' => now()->toDateString(),
            'amount' => 12000,
            'payment_code' => 'PAY-TEST-001',
        ])->assertCreated();

        $contributionId = (int) $stock->json('id');

        $this->postJson("/api/v1/investors/{$investorId}/contributions/{$contributionId}/allocate", [
            'lines' => [
                [
                    'product_code' => 'HALISI-20L',
                    'product_name' => 'Halisi Oil 20L',
                    'qty_purchased' => 100,
                    'unit_cost' => 120,
                    'received_at' => now()->toDateString(),
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('investor_product_batches', [
            'investor_id' => $investorId,
            'product_code' => 'HALISI-20L',
            'qty_purchased' => 100,
        ]);

        $this->getJson("/api/v1/investors/{$investorId}/reports/stock")
            ->assertOk()
            ->assertJsonPath('totals.qty_purchased', 100);

        $this->getJson("/api/v1/investors/{$investorId}/reports/sales")
            ->assertOk()
            ->assertJsonStructure(['lines', 'totals', 'net_profit']);

        $this->getJson("/api/v1/investors/{$investorId}/reports/money-flow")
            ->assertOk()
            ->assertJsonStructure(['events', 'summary']);

        $this->assertSame(1, Investor::query()->where('id', $investorId)->count());
        $this->assertSame(2, InvestorContribution::query()->where('investor_id', $investorId)->count());
        $this->assertSame(1, InvestorProductBatch::query()->where('investor_id', $investorId)->count());
    }

    public function test_module_disabled_blocks_access(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['investors'] = false;
        $modules['investors.reports'] = false;
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $settings['investors'] = ['enable_investors' => false];
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);

        $this->getJson('/api/v1/investors')->assertStatus(403);
    }
}
