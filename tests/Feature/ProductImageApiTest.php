<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\StoredPublicFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ProductImageApiTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->org = Organization::query()->findOrFail($this->user->organization_id);
        Sanctum::actingAs($this->user);
    }

    public function test_retail_catalogue_omits_product_images(): void
    {
        $product = $this->productWithStoredPhoto();

        $res = $this->getJson('/api/v1/products?fields=lean&per_page=50&status=active')
            ->assertOk()
            ->json();

        $row = collect($res['data'] ?? [])->firstWhere('product_code', $product->product_code);
        $this->assertNotNull($row, 'Lean product list should include the item.');
        $this->assertArrayNotHasKey('image_url', $row);
        $this->assertArrayNotHasKey('image_path', $row);
        $this->assertArrayNotHasKey('has_image', $row);
    }

    public function test_retail_cannot_upload_or_download_product_photos(): void
    {
        $product = $this->productWithStoredPhoto();

        $this->postJson('/api/v1/products/'.$product->product_code.'/image/from-url', [
            'url' => 'https://example.com/photo.jpg',
        ])->assertStatus(422);

        $this->get('/api/v1/products/'.$product->product_code.'/image/file')
            ->assertStatus(422);
    }

    public function test_hotel_lean_product_list_includes_image_url_when_photo_exists(): void
    {
        $this->enableHotelIndustry();
        $product = $this->productWithStoredPhoto();

        $res = $this->getJson('/api/v1/products?fields=lean&per_page=50&status=active')
            ->assertOk()
            ->json();

        $row = collect($res['data'] ?? [])->firstWhere('product_code', $product->product_code);
        $this->assertNotNull($row, 'Lean product list should include the photographed item.');
        $this->assertTrue((bool) ($row['has_image'] ?? false));
        $this->assertNotEmpty($row['image_url'] ?? null);
        $this->assertNotEmpty($row['image_version'] ?? null);
        $this->assertStringContainsString('/image/file', (string) $row['image_url']);
        $this->assertStringContainsString('v='.$row['image_version'], (string) $row['image_url']);
    }

    public function test_hotel_product_image_file_can_be_downloaded(): void
    {
        $this->enableHotelIndustry();
        $product = $this->productWithStoredPhoto();

        $ext = strtolower((string) pathinfo((string) $product->image_path, PATHINFO_EXTENSION)) ?: 'jpg';

        $this->get('/api/v1/products/'.$product->product_code.'/image/file?download=1')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="'.$product->product_code.'.'.$ext.'"');
    }

    public function test_hotel_image_file_recovers_relative_path_from_legacy_storage_url(): void
    {
        $this->enableHotelIndustry();
        $product = $this->productWithStoredPhoto();
        $relative = (string) $product->image_path;

        $product->forceFill([
            'image_path' => 'https://old-host.example/storage/'.$relative,
        ])->save();

        $this->get('/api/v1/products/'.$product->product_code.'/image/file')
            ->assertOk();

        $this->assertSame($relative, $product->fresh()->image_path);
    }

    public function test_hotel_image_file_returns_404_when_photo_bytes_missing(): void
    {
        $this->enableHotelIndustry();
        $product = Product::query()
            ->where('organization_id', $this->org->id)
            ->orderBy('product_code')
            ->firstOrFail();
        $product->forceFill([
            'image_path' => 'orgs/missing/products/no-such-file.jpg',
        ])->save();

        $this->get('/api/v1/products/'.$product->product_code.'/image/file')
            ->assertNotFound();
    }

    public function test_hotel_image_file_imports_remote_url_onto_public_disk(): void
    {
        $this->enableHotelIndustry();
        Storage::fake('public');

        $product = Product::query()
            ->where('organization_id', $this->org->id)
            ->orderBy('product_code')
            ->firstOrFail();

        $remote = 'https://cdn.example.com/bar-drink.jpg';
        $product->forceFill(['image_path' => $remote])->save();

        $this->assertSame($remote, $product->fresh()->image_url);

        Http::fake([
            $remote => Http::response($this->jpegBytes(), 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $this->get('/api/v1/products/'.$product->product_code.'/image/file')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $fresh = $product->fresh();
        $this->assertNotSame($remote, $fresh->image_path);
        $this->assertTrue(StoredPublicFile::exists($fresh->image_path));
        $this->assertStringContainsString('/image/file', (string) $fresh->image_url);
    }

    public function test_hotel_remote_image_url_is_not_mutated_with_cache_buster(): void
    {
        $this->enableHotelIndustry();
        $product = Product::query()
            ->where('organization_id', $this->org->id)
            ->orderBy('product_code')
            ->firstOrFail();

        $remote = 'https://cdn.example.com/signed.jpg?X-Amz-Signature=abc&X-Amz-Expires=3600';
        $product->forceFill(['image_path' => $remote])->save();

        $fresh = $product->fresh();
        $this->assertSame($remote, $fresh->image_url);
        $this->assertNotEmpty($fresh->image_version);
        $this->assertStringNotContainsString('v='.$fresh->image_version, (string) $fresh->image_url);
    }

    protected function jpegBytes(): string
    {
        $image = imagecreatetruecolor(24, 24);
        $fill = imagecolorallocate($image, 24, 95, 165);
        imagefill($image, 0, 0, $fill);
        ob_start();
        imagejpeg($image, null, 80);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    protected function enableHotelIndustry(): void
    {
        $this->org->forceFill(['deployment_profile' => 'hotel_bar'])->save();
        $this->user->unsetRelation('organization');
    }

    protected function productWithStoredPhoto(): Product
    {
        $product = Product::query()
            ->where('organization_id', $this->org->id)
            ->whereNotNull('image_path')
            ->where('image_path', '!=', '')
            ->orderBy('product_code')
            ->get()
            ->first(fn (Product $row) => StoredPublicFile::exists($row->image_path));

        $this->assertNotNull($product, 'Demo catalogue should include at least one stored product photo.');

        return $product;
    }
}
