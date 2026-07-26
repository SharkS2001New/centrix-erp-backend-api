<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class QzTraySigningTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
    }

    public function test_certificate_returns_503_when_not_configured(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        Config::set([
            'qz.certificate' => null,
            'qz.private_key' => null,
            'qz.certificate_path' => null,
            'qz.private_key_path' => null,
        ]);

        $this->getJson('/api/v1/qz/certificate')
            ->assertStatus(503)
            ->assertJsonPath('code', 'qz_not_configured');
    }

    public function test_certificate_and_sign_with_generated_key(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);

        openssl_pkey_export($key, $privateKeyPem);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $publicKeyPem = $details['key'];

        $dn = ['commonName' => 'Centrix QZ Test'];
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha512']);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha512']);
        $this->assertNotFalse($cert);
        openssl_x509_export($cert, $certificatePem);

        Config::set([
            'qz.certificate' => $certificatePem,
            'qz.private_key' => $privateKeyPem,
            'qz.certificate_path' => null,
            'qz.private_key_path' => null,
        ]);

        $certResponse = $this->getJson('/api/v1/qz/certificate');
        $certResponse->assertOk();
        $certResponse->assertJsonStructure(['certificate']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $certResponse->json('certificate'));

        $payload = 'qz-tray-test-'.bin2hex(random_bytes(8));
        $signResponse = $this->postJson('/api/v1/qz/sign', ['to_sign' => $payload]);
        $signResponse->assertOk();
        $signResponse->assertJsonStructure(['signature']);

        $signature = base64_decode((string) $signResponse->json('signature'), true);
        $this->assertNotFalse($signature);

        $verified = openssl_verify($payload, $signature, $publicKeyPem, OPENSSL_ALGO_SHA512);
        $this->assertSame(1, $verified);
    }
}
