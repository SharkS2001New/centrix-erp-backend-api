<?php

namespace Tests\Unit;

use App\Services\Auth\PasswordPolicy;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function test_normalize_input_strips_copy_paste_whitespace(): void
    {
        $this->assertSame('Secret123', PasswordPolicy::normalizeInput("Secret123\n"));
        $this->assertSame('Secret123', PasswordPolicy::normalizeInput("  Secret123  "));
        $this->assertSame('Secret123', PasswordPolicy::normalizeInput("\u{FEFF}Secret123\r\n"));
    }
}
