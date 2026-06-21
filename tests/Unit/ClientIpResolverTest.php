<?php

namespace Tests\Unit;

use App\Support\ClientIpResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientIpResolverTest extends TestCase
{
    #[DataProvider('cidrCases')]
    public function test_ip_in_cidr(string $ip, string $cidr, bool $expected): void
    {
        $this->assertSame($expected, ClientIpResolver::ipInCidr($ip, $cidr));
    }

    public static function cidrCases(): array
    {
        return [
            ['196.201.214.200', '196.201.214.0/24', true],
            ['196.201.213.114', '196.201.213.0/24', true],
            ['192.168.1.1', '196.201.214.0/24', false],
            ['196.201.214.200', '196.201.214.200', true],
            ['10.0.0.1', '196.201.214.200', false],
        ];
    }

    public function test_matches_allowlist_with_explicit_ip(): void
    {
        $this->assertTrue(ClientIpResolver::matchesAllowlist(
            '196.201.212.69',
            [],
            ['196.201.212.69'],
        ));
    }
}
