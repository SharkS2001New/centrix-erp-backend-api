<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_normalize_keeps_local_zero_prefix(): void
    {
        $this->assertSame('0712345678', PhoneNumber::normalize('+254 712 345 678'));
    }

    public function test_to_e164_from_local_number(): void
    {
        $this->assertSame('254712345678', PhoneNumber::toE164('0712345678'));
    }
}
