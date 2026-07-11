<?php

namespace Tests\Unit;

use App\Services\Auth\TotpService;
use Tests\TestCase;

class TotpServiceTest extends TestCase
{
    public function test_generates_and_verifies_code(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();
        $code = $totp->codeAt($secret, (int) floor(time() / 30));

        $this->assertTrue($totp->verify($secret, $code));
        $this->assertFalse($totp->verify($secret, '000000'));
        $this->assertStringStartsWith('otpauth://totp/', $totp->otpauthUrl($secret, 'user@example.com'));
    }
}
