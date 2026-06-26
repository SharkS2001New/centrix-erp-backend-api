<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceErrorTranslator;
use Tests\TestCase;

class KraDeviceErrorTranslatorTest extends TestCase
{
    public function test_translates_invoice_number_error_code_314(): void
    {
        $raw = 'Signature generation failed: receiptNo is error (Code 314)';

        $result = KraDeviceErrorTranslator::translate($raw);

        $this->assertSame('314', $result['code']);
        $this->assertStringContainsString('receipt reference number', strtolower($result['message']));
        $this->assertSame($raw, $result['technical_message']);
    }

    public function test_translates_plu_not_found_by_pattern(): void
    {
        $raw = 'NO FIND PLU DATA for item ABC123';

        $result = KraDeviceErrorTranslator::translate($raw);

        $this->assertStringContainsString('not found on the KRA device', $result['message']);
    }

    public function test_unwraps_http_exception_json_body(): void
    {
        $raw = 'HTTP request returned status code 500: {"success":false,"message":"Signature generation failed: receiptNo is error (Code 314)"}';

        $result = KraDeviceErrorTranslator::translate($raw);

        $this->assertSame('314', $result['code']);
        $this->assertStringNotContainsString('HTTP request returned', $result['message']);
    }

    public function test_translates_device_unreachable_pattern(): void
    {
        $raw = 'Could not reach KRA device: cURL error 7: Connection refused';

        $result = KraDeviceErrorTranslator::translate($raw);

        $this->assertStringContainsString('Could not reach the KRA device', $result['message']);
    }
}
