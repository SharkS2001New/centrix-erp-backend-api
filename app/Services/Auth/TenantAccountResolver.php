<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserMembership;
use App\Services\Auth\UsernameNormalizer;

class TenantAccountResolver
{
    public function resolve(Organization $organization, string $identifier): ?TenantAccount
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $account = $this->resolveByUsername($organization, $identifier);
        if ($account) {
            return $account;
        }

        if (str_contains($identifier, '@')) {
            return $this->resolveByEmail($organization, $identifier);
        }

        return null;
    }

    public function resolvePlatformSuperAdminByEmail(string $email): ?TenantAccount
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $user = User::query()
            ->where('is_super_admin', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->with('organization')
            ->first();

        if (! $user?->organization) {
            return null;
        }

        return new TenantAccount($user, $user->organization);
    }

    protected function resolveByUsername(Organization $organization, string $username): ?TenantAccount
    {
        $normalized = UsernameNormalizer::forLookup($username);
        $user = User::query()
            ->where('organization_id', $organization->id)
            ->whereUsernameInsensitive($username)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($user) {
            return new TenantAccount($user, $organization);
        }

        $membership = UserMembership::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('UPPER(username) = ?', [$normalized])
            ->where('is_active', true)
            ->with('user')
            ->first();

        if ($membership?->user && $membership->user->is_active && ! $membership->user->deleted_at) {
            return new TenantAccount($membership->user, $organization, $membership);
        }

        return null;
    }

    protected function resolveByEmail(Organization $organization, string $email): ?TenantAccount
    {
        $email = strtolower(trim($email));

        $user = User::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($user) {
            return new TenantAccount($user, $organization);
        }

        return null;
    }

    public function resolveForCanonicalUser(Organization $organization, int $canonicalUserId): ?TenantAccount
    {
        $user = User::query()
            ->where('id', $canonicalUserId)
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($user) {
            return new TenantAccount($user, $organization);
        }

        $membership = UserMembership::query()
            ->where('user_id', $canonicalUserId)
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->with('user')
            ->first();

        if ($membership?->user && $membership->user->is_active && ! $membership->user->deleted_at) {
            return new TenantAccount($membership->user, $organization, $membership);
        }

        return null;
    }

    /** @return list<array{company_code: string, org_name: string, organization_id: int, username: string, access_scope: string, is_admin: bool}> */
    public function membershipsForCanonicalUser(int $canonicalUserId): array
    {
        $entries = [];

        $primary = User::query()
            ->where('id', $canonicalUserId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->with('organization')
            ->first();

        if ($primary?->organization) {
            $entries[] = $this->entryFromUser($primary);
        }

        $linked = UserMembership::query()
            ->where('user_id', $canonicalUserId)
            ->where('is_active', true)
            ->with(['organization', 'user'])
            ->get();

        foreach ($linked as $membership) {
            if (! $membership->organization || ! $membership->user?->is_active) {
                continue;
            }
            $entries[] = [
                'organization_id' => $membership->organization_id,
                'company_code' => $membership->organization->company_code,
                'org_name' => $membership->organization->org_name,
                'username' => $membership->username,
                'access_scope' => $membership->access_scope,
                'is_admin' => (bool) $membership->is_admin,
            ];
        }

        return $entries;
    }

    /** @return array{organization_id: int, company_code: string, org_name: string, username: string, access_scope: string, is_admin: bool} */
    protected function entryFromUser(User $user): array
    {
        return [
            'organization_id' => $user->organization_id,
            'company_code' => $user->organization->company_code,
            'org_name' => $user->organization->org_name,
            'username' => $user->username,
            'access_scope' => $user->access_scope ?? 'org',
            'is_admin' => (bool) $user->is_admin,
        ];
    }
}
