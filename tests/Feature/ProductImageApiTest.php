<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Product;
use App\Models\User;
use App\Support\StoredPublicFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ProductImageApiTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());
    }

    public function test_lean_product_list_includes_image_url_when_photo_exists(): void
    {
        $product = $this->productWithStoredPhoto();

        $res = $this->getJson('/api/v1/products?fields=lean&per_page=50&status=active')
            ->assertOk()
            ->json();

        $row = collect($res['data'] ?? [])->firstWhere('product_code', $product->product_code);
        $this->assertNotNull($row, 'Lean product list should include the photographed item.');
        $this->assertTrue((bool) ($row['has_image'] ?? false));
        $this->assertNotEmpty($row['image_url'] ?? null);
        $this->assertStringContainsString('/image/file', (string) $row['image_url']);
    }

    public function test_product_image_file_can_be_downloaded(): void
    {
        $product = $this->productWithStoredPhoto();

        $ext = strtolower((string) pathinfo((string) $product->image_path, PATHINFO_EXTENSION)) ?: 'jpg';

        $this->get('/api/v1/products/'.$product->product_code.'/image/file?download=1')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="'.$product->product_code.'.'.$ext.'"');
    }

    protected function productWithStoredPhoto(): Product
    {
        $product = Product::query()
            ->whereNotNull('image_path')
            ->where('image_path', '!=', '')
            ->orderBy('product_code')
            ->get()
            ->first(fn (Product $row) => StoredPublicFile::exists($row->image_path));

        $this->assertNotNull($product, 'Demo catalogue should include at least one stored product photo.');

        return $product;
    }
}
