<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppWebhookSignatureValidator;
use Illuminate\Http\Request;
use Tests\TestCase;

class WhatsAppWebhookSignatureValidatorTest extends TestCase
{
    public function test_accepts_valid_signature(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);

        $body = '{"object":"whatsapp_business_account"}';
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        $request = Request::create('/webhooks/whatsapp', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Hub-Signature-256', $signature);

        $validator = new WhatsAppWebhookSignatureValidator;

        $this->assertTrue($validator->isValid($request));
    }

    public function test_rejects_invalid_signature_when_secret_configured(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);

        $body = '{"object":"whatsapp_business_account"}';
        $request = Request::create('/webhooks/whatsapp', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Hub-Signature-256', 'sha256=invalid');

        $validator = new WhatsAppWebhookSignatureValidator;

        $this->assertFalse($validator->isValid($request));
    }

    public function test_skips_validation_when_secret_not_configured(): void
    {
        config(['whatsapp.app_secret' => null]);

        $request = Request::create('/webhooks/whatsapp', 'POST');

        $validator = new WhatsAppWebhookSignatureValidator;

        $this->assertFalse($validator->isConfigured());
        $this->assertTrue($validator->isValid($request));
    }
}
