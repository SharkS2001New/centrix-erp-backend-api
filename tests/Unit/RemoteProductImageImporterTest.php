<?php

namespace Tests\Unit;

use App\Support\RemoteProductImageImporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RemoteProductImageImporterTest extends TestCase
{
    public function test_imports_a_public_jpeg_and_stores_it(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/cola.jpg' => Http::response($this->jpegBytes(), 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $stored = (new RemoteProductImageImporter)->import(
            'https://cdn.example.com/cola.jpg',
            'orgs/TEST/products/COLA',
        );

        $this->assertNotEmpty($stored['path']);
        Storage::disk('public')->assertExists($stored['path']);
    }

    public function test_rejects_private_and_local_urls(): void
    {
        $importer = new RemoteProductImageImporter;

        try {
            $importer->import('http://127.0.0.1/secret.jpg', 'orgs/TEST/products/X');
            $this->fail('Expected ValidationException for loopback URL.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('url', $e->errors());
        }

        try {
            $importer->import('http://localhost/photo.png', 'orgs/TEST/products/X');
            $this->fail('Expected ValidationException for localhost URL.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('url', $e->errors());
        }
    }

    public function test_rejects_html_error_pages(): void
    {
        Http::fake([
            'https://cdn.example.com/missing.jpg' => Http::response('<html>not found</html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        try {
            (new RemoteProductImageImporter)->import(
                'https://cdn.example.com/missing.jpg',
                'orgs/TEST/products/X',
            );
            $this->fail('Expected ValidationException for HTML body.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('url', $e->errors());
        }
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
}
