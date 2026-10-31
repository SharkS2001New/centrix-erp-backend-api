<?php

namespace App\Services\Platform\Speed;

use Illuminate\Http\Request;

class PlatformSpeedRouteNormalizer
{
    public function routeKey(Request $request): string
    {
        $route = $request->route();
        if ($route) {
            $uri = (string) ($route->uri() ?: '');
            if ($uri !== '') {
                return $this->normalizePath($uri);
            }
            $name = $route->getName();
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $this->normalizePath($request->path());
    }

    public function normalizePath(string $path): string
    {
        $path = trim($path);
        $path = preg_replace('#^/+#', '', $path) ?? $path;
        // Collapse numeric / UUID segments.
        $path = preg_replace(
            '#/(?:\d+|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})(?=/|$)#',
            '/{id}',
            $path,
        ) ?? $path;
        $path = preg_replace('#\{[^}/]+\}#', '{id}', $path) ?? $path;

        return substr($path, 0, 220);
    }

    public function shouldExclude(Request $request): bool
    {
        if (! config('platform_speed.enabled') || ! config('platform_speed.record_requests')) {
            return true;
        }

        $path = ltrim($request->path(), '/');
        foreach ((array) config('platform_speed.exclude_path_prefixes', []) as $prefix) {
            $prefix = ltrim((string) $prefix, '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                return true;
            }
        }

        $ua = strtolower((string) $request->userAgent());
        foreach ((array) config('platform_speed.exclude_user_agents', []) as $needle) {
            if ($needle !== '' && str_contains($ua, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
