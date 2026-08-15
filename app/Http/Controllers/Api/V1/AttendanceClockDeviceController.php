<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class AttendanceClockDeviceController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return \App\Models\AttendanceClockDevice::class;
    }

    protected function applySearch($query, string $q): void
    {
        $query->where(function ($sub) use ($q) {
            $sub->where('device_no', 'like', "%{$q}%")
                ->orWhere('location', 'like', "%{$q}%")
                ->orWhere('host', 'like', "%{$q}%");
        });
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        $data = $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'device_no' => $req . 'string|max:50',
            'location' => 'nullable|string|max:200',
            'is_active' => 'nullable|boolean',
            'provider' => 'nullable|in:generic,hikvision',
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:100',
            'password' => 'nullable|string|max:200',
            'use_https' => 'nullable|boolean',
        ]);

        if (array_key_exists('password', $data)) {
            $password = $data['password'];
            unset($data['password']);
            if ($password !== null && $password !== '') {
                $data['password_encrypted'] = Crypt::encryptString($password);
            }
        }

        if (! isset($data['provider']) && ! $updating) {
            $data['provider'] = ! empty($data['host']) ? 'hikvision' : 'generic';
        }

        return $data;
    }

    /**
     * Issue a long-lived Sanctum token + agent config for the downloadable LAN attendance agent.
     * Optional body fields override / fill Hikvision LAN settings (and can persist host if empty).
     */
    public function issueAgentPackage(Request $request, string $id)
    {
        $device = $this->findScoped($id);
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:100',
            'password' => 'nullable|string|max:200',
            'use_https' => 'nullable|boolean',
            'centrix_api_url' => 'nullable|string|max:500',
            'persist_device' => 'nullable|boolean',
        ]);

        $host = filled($data['host'] ?? null) ? trim((string) $data['host']) : $device->host;
        $port = array_key_exists('port', $data) && $data['port'] !== null
            ? (int) $data['port']
            : ($device->port ?: 80);
        $username = filled($data['username'] ?? null)
            ? trim((string) $data['username'])
            : ($device->username ?: 'admin');
        $password = filled($data['password'] ?? null)
            ? (string) $data['password']
            : ($device->plainPassword() ?? '');
        $useHttps = array_key_exists('use_https', $data)
            ? (bool) $data['use_https']
            : (bool) $device->use_https;

        if (($data['persist_device'] ?? true) === true) {
            $dirty = false;
            if (filled($host) && $device->host !== $host) {
                $device->host = $host;
                $dirty = true;
            }
            if ($device->port !== $port) {
                $device->port = $port;
                $dirty = true;
            }
            if (filled($username) && $device->username !== $username) {
                $device->username = $username;
                $dirty = true;
            }
            if ((bool) $device->use_https !== $useHttps) {
                $device->use_https = $useHttps;
                $dirty = true;
            }
            if (filled($data['password'] ?? null)) {
                $device->setPlainPassword((string) $data['password']);
                $dirty = true;
            }
            if ($device->provider === 'generic' && filled($host)) {
                $device->provider = 'hikvision';
                $dirty = true;
            }
            if ($dirty) {
                $device->save();
            }
        }

        $tokenName = 'attendance-agent:'.$device->device_no;
        $user->tokens()->where('name', $tokenName)->delete();
        $token = $user->createToken($tokenName, ['*'], now()->addYears(5));

        $apiUrl = filled($data['centrix_api_url'] ?? null)
            ? rtrim((string) $data['centrix_api_url'], '/')
            : rtrim((string) config('app.url'), '/').'/api/v1';

        return response()->json([
            'config' => [
                'centrixApiUrl' => $apiUrl,
                'centrixToken' => $token->plainTextToken,
                'deviceId' => (int) $device->id,
                'deviceNo' => $device->device_no,
                'hikvision' => [
                    'host' => (string) ($host ?? ''),
                    'port' => $port,
                    'username' => $username,
                    'password' => $password,
                    'useHttps' => $useHttps,
                ],
                'pollIntervalSeconds' => 300,
                'lookbackMinutes' => 360,
            ],
            'token_name' => $tokenName,
            'expires_at' => optional($token->accessToken->expires_at)?->toIso8601String(),
            'needs_device_ip' => ! filled($host),
            'needs_device_password' => $password === '',
            'device' => $device->fresh(),
        ]);
    }
}
