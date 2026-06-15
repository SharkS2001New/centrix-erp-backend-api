<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class QuickBooksSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());
    }

    public function test_finance_settings_can_store_quickbooks_credentials(): void
    {
        $response = $this->patchJson('/api/v1/erp/settings/finance', [
            'accounting_mode' => 'external',
            'accounting_provider' => 'quickbooks',
            'quickbooks' => [
                'client_id' => 'QB-CLIENT-TEST',
                'client_secret' => 'QB-SECRET-TEST',
                'redirect_uri' => 'http://localhost:8000/api/v1/accounting/quickbooks/callback',
                'environment' => 'sandbox',
            ],
        ])->assertOk();

        $response->assertJsonPath('finance.quickbooks.client_id', 'QB-CLIENT-TEST');
        $response->assertJsonPath('finance.quickbooks.client_secret', '********');
        $response->assertJsonPath('finance.quickbooks_status.ready', true);

        $this->getJson('/api/v1/accounting/quickbooks/connect-url')
            ->assertOk()
            ->assertJsonStructure(['authorization_url']);
    }
}
