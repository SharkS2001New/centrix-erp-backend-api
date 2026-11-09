<?php

namespace Tests\Feature;

use App\Models\PlatformSetupProfile;
use App\Models\User;
use App\Services\Erp\SetupProfileMenuService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SetupProfileBusinessTypesTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_business_types_are_exclusive_across_setup_types(): void
    {
        $menus = app(SetupProfileMenuService::class);

        $this->assertContains('Pharmacy', $menus->businessTypesForProfile('small_shop'));
        $this->assertSame(
            ['key' => 'small_shop', 'label' => 'Small shop (backoffice sales only)'],
            $menus->businessTypeOwners()['Pharmacy'] ?? null,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Pharmacy is already on Small shop');
        $menus->assertExclusiveBusinessTypes(['Pharmacy'], 'wholesale_retail');
    }

    public function test_platform_admin_can_create_setup_type_with_available_business_types(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $response = $this->postJson('/api/v1/admin/setup-profiles', [
            'name' => 'Optics specialty',
            'industry' => 'commerce',
            'business_types' => [],
        ])->assertCreated()
            ->assertJsonPath('data.label', 'Optics specialty')
            ->assertJsonPath('data.custom', true);

        $key = (string) $response->json('data.key');
        $this->assertNotSame('', $key);
        $this->assertSame([], $response->json('data.business_types'));

        // Optical is still owned by small_shop — claiming it must fail.
        $this->putJson("/api/v1/admin/setup-profiles/{$key}", [
            'business_types' => ['Optical'],
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Each business type can only belong to one setup type. Optical is already on Small shop (backoffice sales only).']);

        $this->deleteJson("/api/v1/admin/setup-profiles/{$key}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertFalse(
            PlatformSetupProfile::query()->where('profile_key', $key)->exists()
        );
    }

    public function test_create_rejects_business_type_already_claimed(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $this->postJson('/api/v1/admin/setup-profiles', [
            'name' => 'Duplicate pharmacy',
            'industry' => 'commerce',
            'business_types' => ['Pharmacy'],
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Each business type can only belong to one setup type. Pharmacy is already on Small shop (backoffice sales only).']);
    }

    public function test_setup_profile_index_exposes_business_type_owners(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $this->getJson('/api/v1/admin/setup-profiles')
            ->assertOk()
            ->assertJsonPath('business_type_owners.Pharmacy.key', 'small_shop')
            ->assertJsonPath('business_type_owners.Supermarket.key', 'supermarket')
            ->assertJsonPath('business_type_owners.Wholesale shop.key', 'wholesale_retail');
    }
}
