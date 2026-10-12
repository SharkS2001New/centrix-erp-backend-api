<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceService;
use PHPUnit\Framework\TestCase;

class KraEtimsQrPayloadTest extends TestCase
{
    public function test_junk_year_byte_is_kept_and_repaired_for_the_qr(): void
    {
        $service = new KraDeviceService('http://localhost:4000', 'DEJA02220240050', 'P000000000A');
        $raw = "\xFAb26-10-08 10:04 DEJA02220240050 0000311 https://etims.kra.go.ke/common/link/etims/receipt/indexEtimsReceiptData?Data=ABC123";

        $clean = $service->sanitizeEtimsQrPayload($raw);

        $this->assertSame(
            '2026-10-08 10:04 DEJA02220240050 0000311 https://etims.kra.go.ke/common/link/etims/receipt/indexEtimsReceiptData?Data=ABC123',
            $clean,
        );
        $this->assertTrue($service->looksLikeEtimsQrPayload($raw));
    }

    public function test_plain_verification_url_is_unchanged(): void
    {
        $service = new KraDeviceService('http://localhost:4000', 'DEJA02220240050', 'P000000000A');

        $this->assertSame(
            'https://etims.kra.go.ke/common/link/etims/receipt/indexEtimsReceiptData?Data=ABC123',
            $service->sanitizeEtimsQrPayload('https://etims.kra.go.ke/common/link/etims/receipt/indexEtimsReceiptData?Data=ABC123'),
        );
    }
}
