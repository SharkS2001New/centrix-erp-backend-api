<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ManagerReportCatalogTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_mobile_catalog_excludes_active_cart_reservations(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/manager/reports/catalog');
        $response->assertOk();

        $keys = $this->collectCatalogReportKeys($response->json());
        $this->assertNotContains('stock-reservations', $keys);
    }

    public function test_manager_customer_search_returns_matches(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/manager/customers/search?q=admin&per_page=5');
        $response->assertOk();
        $response->assertJsonStructure(['data']);
    }

    public function test_manager_supplier_search_returns_matches(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/manager/suppliers/search?q=sup&per_page=5');
        $response->assertOk();
        $response->assertJsonStructure(['data']);
    }

    /** @param  array<string, mixed>  $payload */
    private function collectCatalogReportKeys(array $payload): array
    {
        $keys = [];

        foreach ($payload['featured'] ?? [] as $item) {
            if (is_array($item) && ! empty($item['key'])) {
                $keys[] = (string) $item['key'];
            }
        }

        foreach ($payload['categories'] ?? [] as $category) {
            if (! is_array($category)) {
                continue;
            }
            foreach ($category['reports'] ?? [] as $item) {
                if (is_array($item) && ! empty($item['key'])) {
                    $keys[] = (string) $item['key'];
                }
            }
        }

        return $keys;
    }
}
