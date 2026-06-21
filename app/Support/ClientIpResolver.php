<?php

namespace App\Support;

use Illuminate\Http\Request;

class ClientIpResolver
{
    public static function fromRequest(Request $request): ?string
    {
        $ip = $request->ip();

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /** @param  list<string>  $cidrs */
    public static function matchesAllowlist(?string $ip, array $cidrs, array $ips = []): bool
    {
        if ($ip === null) {
            return false;
        }

        if (in_array($ip, $ips, true)) {
            return true;
        }

        foreach ($cidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $maskBits] = explode('/', $cidr, 2);
        $maskBits = (int) $maskBits;

        if (
            ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || ! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || $maskBits < 0
            || $maskBits > 32
        ) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $maskBits === 0 ? 0 : (-1 << (32 - $maskBits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
