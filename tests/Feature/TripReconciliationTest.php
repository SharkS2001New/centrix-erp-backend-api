<?php

namespace Tests\Feature;

use App\Models\DispatchTrip;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class TripReconciliationTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableDistributionModules(User $user): void
    {
        $org = Organization::findOrFail($user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $settings = $org->module_settings ?? [];
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'enable_distribution_ops' => true,
            'enable_cod_reconciliation' => true,
            'require_trip_cash_settlement' => true,
        ]);
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);
    }

    public function test_start_trip_requires_locked_loading_list_when_lines_exist(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $trip = DispatchTrip::query()->where('status', 'draft')->with('loadingList.lines')->first();
        if (! $trip || ! $trip->loadingList || $trip->loadingList->lines->isEmpty()) {
            $this->markTestSkipped('No draft trip with loading list lines in demo seed.');
        }

        $this->postJson("/api/v1/dispatch-trips/{$trip->id}/start")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Lock the loading list before starting the trip.']);
    }

    public function test_distribution_reports_endpoints_are_registered(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/reports/dispatch-trips?per_page=5')->assertOk();
        $this->getJson('/api/v1/reports/trip-cash-settlement?per_page=5')->assertOk();
        $this->getJson('/api/v1/reports/pod-compliance?per_page=5')->assertOk();
        $this->getJson('/api/v1/reports/driver-deliveries?per_page=5')->assertOk();
    }
}
