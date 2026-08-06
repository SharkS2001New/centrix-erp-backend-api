<?php

namespace Tests\Feature;

use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ReferencePickerTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function seedLicense(User $user): void
    {
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
    }

    public function test_reference_users_and_vats_do_not_require_admin_or_catalogue_view(): void
    {
        PermissionMatrixService::ensure();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->seedLicense($cashier);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/vats')->assertForbidden();

        $this->getJson('/api/v1/reference/users?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/vats?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/categories?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/uoms?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/suppliers?per_page=5')->assertOk();
    }

    public function test_kra_invoices_permission_is_registered(): void
    {
        PermissionMatrixService::ensure();
        $codes = PermissionMatrixService::allRegistryCodes();

        $this->assertContains('catalogue.kra_invoices.view', $codes);
    }
}
