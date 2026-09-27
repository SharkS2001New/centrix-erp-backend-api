<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Read / bump the Centrix product semver from the repo VERSION file.
 */
class CentrixVersion
{
    public static function current(): string
    {
        $fromConfig = trim((string) config('centrix.version', ''));
        if ($fromConfig !== '' && preg_match('/^\d+\.\d+\.\d+$/', $fromConfig)) {
            return $fromConfig;
        }

        $path = self::filePath();
        if (is_readable($path)) {
            $raw = trim((string) file_get_contents($path));
            if (preg_match('/^\d+\.\d+\.\d+$/', $raw)) {
                return $raw;
            }
        }

        $env = trim((string) env('CENTRIX_VERSION', ''));
        if ($env !== '' && preg_match('/^\d+\.\d+\.\d+$/', $env)) {
            return $env;
        }

        return '0.0.0';
    }

    public static function label(): string
    {
        return 'v'.self::current();
    }

    /**
     * @param  'major'|'minor'|'patch'  $part
     */
    public static function bump(string $part = 'patch'): string
    {
        $part = strtolower(trim($part));
        if (! in_array($part, ['major', 'minor', 'patch'], true)) {
            throw new InvalidArgumentException('Version part must be major, minor, or patch.');
        }

        [$major, $minor, $patch] = array_map('intval', explode('.', self::current()));

        if ($part === 'major') {
            $major++;
            $minor = 0;
            $patch = 0;
        } elseif ($part === 'minor') {
            $minor++;
            $patch = 0;
        } else {
            $patch++;
        }

        $next = sprintf('%d.%d.%d', $major, $minor, $patch);
        self::write($next);

        return $next;
    }

    public static function write(string $version): void
    {
        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new InvalidArgumentException('Version must be semver X.Y.Z');
        }

        $path = self::filePath();
        if (file_put_contents($path, $version."\n") === false) {
            throw new InvalidArgumentException('Could not write VERSION file.');
        }

        config(['centrix.version' => $version]);
    }

    public static function filePath(): string
    {
        return base_path((string) config('centrix.version_file', 'VERSION'));
    }
}
