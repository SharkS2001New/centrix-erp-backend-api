<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DispatchTrip;
use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class DispatchTripScopedAccessTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableDistributionModules(User $user): void
    {
        $org = Organization::findOrFail($user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $org->update(['enabled_modules' => $modules]);

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $org->id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
    }

    public function test_dispatch_trip_show_resolves_via_branch_when_organization_id_is_stale(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $otherOrg = Organization::create([
            'company_code' => 'STALE-ORG-'.uniqid(),
            'org_name' => 'Stale Org Marker',
            'org_email' => 'stale-org@test.com',
            'primary_tel' => '0700111222',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'distribution',
        ]);

        $trip = DispatchTrip::create([
            'branch_id' => $admin->branch_id,
            'trip_code' => 'TRIP-STALE-'.uniqid(),
            'scheduled_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        DB::table('dispatch_trips')
            ->where('id', $trip->id)
            ->update(['organization_id' => $otherOrg->id]);

        $this->getJson("/api/v1/dispatch-trips/{$trip->id}")
            ->assertOk()
            ->assertJsonPath('id', $trip->id);
    }

    public function test_dispatch_trip_show_rejects_other_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $otherOrg = Organization::create([
            'company_code' => 'OTHER-ORG-'.uniqid(),
            'org_name' => 'Other Org',
            'org_email' => 'other-org@test.com',
            'primary_tel' => '0700333444',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'distribution',
        ]);
        $otherBranch = Branch::create([
            'organization_id' => $otherOrg->id,
            'branch_code' => 'OTHER-MAIN',
            'branch_name' => 'Other Main',
            'is_active' => true,
        ]);

        $trip = DispatchTrip::create([
            'organization_id' => $otherOrg->id,
            'branch_id' => $otherBranch->id,
            'trip_code' => 'TRIP-OTHER-'.uniqid(),
            'scheduled_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $this->getJson("/api/v1/dispatch-trips/{$trip->id}")
            ->assertNotFound();
    }

    public function test_non_numeric_dispatch_trip_id_returns_404(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dispatch-trips/undefined')->assertNotFound();
        $this->getJson('/api/v1/dispatch-trips/0')->assertNotFound();
    }
}
