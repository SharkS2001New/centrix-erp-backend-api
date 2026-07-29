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
        $this->assertStringContainsString('receipt or invoice reference', strtolower($result['message']));
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

    public function test_translates_credit_note_relevant_invoice_error(): void
    {
        $raw = 'relevantInvoiceNumber is error (Code 313)';

        $result = KraDeviceErrorTranslator::translate($raw);

        $this->assertSame('313', $result['code']);
        $this->assertStringContainsString('original invoice reference', strtolower($result['message']));
    }

    public function test_translates_plu_same_name_error(): void
    {
        $raw = 'E353: THE SAME NAME';

        $result = KraDeviceErrorTranslator::translate($raw);

        $this->assertSame('353', $result['code']);
        $this->assertStringContainsString('already', strtolower($result['message']));
    }

    public function test_translates_519_communication_failure(): void
    {
        $raw = '519 error code, aborted without a reason';

        $result = KraDeviceErrorTranslator::translate($raw);

        $this->assertSame('519', $result['code']);
        $this->assertStringContainsString('not communicating', strtolower($result['message']));
        $this->assertStringNotContainsString('519 error code', $result['message']);
    }

    public function test_translates_aborted_without_reason_pattern(): void
    {
        $raw = 'signal is aborted without a reason';

        $result = KraDeviceErrorTranslator::translate($raw);

        $this->assertStringContainsString('stopped responding', strtolower($result['message']));
    }
}
