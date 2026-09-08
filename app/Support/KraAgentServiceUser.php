<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Org-owned machine account for CentrixKraAgent tokens.
 * Tokens are not bound to the admin who clicked Download.
 */
final class KraAgentServiceUser
{
    public static function usernameForOrganization(int $organizationId): string
    {
        return '__centrix_kra_agent_'.$organizationId;
    }

    public static function isServiceUsername(?string $username): bool
    {
        if (! is_string($username) || $username === '') {
            return false;
        }

        return str_starts_with(strtoupper($username), '__CENTRIX_KRA_AGENT_');
    }

    public static function resolve(Organization $organization): User
    {
        $orgId = (int) $organization->id;
        $username = self::usernameForOrganization($orgId);

        $user = User::withTrashed()
            ->where('organization_id', $orgId)
            ->whereUsernameInsensitive($username)
            ->first();

        if ($user) {
            if ($user->trashed()) {
                $user->restore();
            }
            $dirty = false;
            if (! $user->is_active) {
                $user->is_active = true;
                $dirty = true;
            }
            if ($user->is_admin || $user->is_super_admin) {
                $user->is_admin = false;
                $user->is_super_admin = false;
                $dirty = true;
            }
            if ($dirty) {
                $user->save();
            }

            return $user;
        }

        return User::query()->create([
            'organization_id' => $orgId,
            'role_id' => self::roleIdForOrganization($orgId),
            'username' => $username,
            'email' => null,
            'password' => Hash::make(Str::random(64)),
            'full_name' => 'Centrix KRA Agent',
            'is_admin' => false,
            'is_super_admin' => false,
            'is_active' => true,
            'is_mobile_user' => false,
            'must_change_password' => false,
            'login_channels' => [],
            'access_scope' => 'org',
        ]);
    }

    protected static function roleIdForOrganization(int $organizationId): int
    {
        $fromAdmin = User::query()
            ->where('organization_id', $organizationId)
            ->where('is_admin', true)
            ->where('is_active', true)
            ->whereNotNull('role_id')
            ->value('role_id');
        if ($fromAdmin) {
            return (int) $fromAdmin;
        }

        $any = User::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('role_id')
            ->value('role_id');
        if ($any) {
            return (int) $any;
        }

        $roleId = \App\Models\Role::query()
            ->where('organization_id', $organizationId)
            ->value('id');
        if ($roleId) {
            return (int) $roleId;
        }

        throw new \RuntimeException('Cannot create KRA agent service user: organization has no roles.');
    }
}
