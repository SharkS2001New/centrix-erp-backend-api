<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ResourceControllerCompatibilityTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_products_index_loads_without_controller_signature_fatal_error(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson('/api/v1/products?per_page=5')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function test_customers_index_loads_without_controller_signature_fatal_error(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson('/api/v1/customers?per_page=5')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }
}
