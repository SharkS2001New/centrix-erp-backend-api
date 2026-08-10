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
}
