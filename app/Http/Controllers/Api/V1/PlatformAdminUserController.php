<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\TwoFactorService;
use App\Services\Platform\PlatformAdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformAdminUserController
{
    public function catalog()
    {
        return response()->json([
            'permissions' => PlatformAdminPermissions::catalog(),
            'full_access_code' => PlatformAdminPermissions::FULL,
            'notes' => [
                'null_permissions' => 'Omit permissions or send null for unrestricted platform access (owner).',
                'two_factor' => 'Every platform admin must enable 2FA on first sign-in before platform tools unlock.',
            ],
        ]);
    }

    public function index(Request $request)
    {
        $this->assertCanManageAdmins($request->user());

        $platformOrgId = $this->platformOrganizationId();
        $rows = User::query()
            ->where('organization_id', $platformOrgId)
            ->where('is_super_admin', true)
            ->orderBy('full_name')
            ->orderBy('username')
            ->get()
            ->map(fn (User $user) => $this->serialize($user, $request->user()));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        $this->assertCanManageAdmins($actor);

        $platformOrgId = $this->platformOrganizationId();
        $org = Organization::query()->findOrFail($platformOrgId);

        $data = $request->validate([
            'username' => [
                'required',
                'string',
                'max:120',
                Rule::unique('users', 'username')->where(fn ($q) => $q->where('organization_id', $platformOrgId)->whereNull('deleted_at')),
            ],
            'email' => 'required|email|max:191',
            'full_name' => 'required|string|max:191',
            'password' => PasswordPolicy::validationRules($platformOrgId, confirmed: true),
            'is_active' => 'sometimes|boolean',
            'platform_permissions' => 'nullable|array',
            'platform_permissions.*' => 'string|max:64',
            'full_access' => 'sometimes|boolean',
        ]);

        if (empty($data['full_access']) && ! array_key_exists('platform_permissions', $data)) {
            throw ValidationException::withMessages([
                'platform_permissions' => ['Select at least one platform area, or grant full access.'],
            ]);
        }
        $permissions = $this->resolvePermissionsInput($data, $actor);
        if ($permissions !== null && $permissions === []) {
            throw ValidationException::withMessages([
                'platform_permissions' => ['Select at least one platform area, or grant full access.'],
            ]);
        }

        $branchId = User::query()
            ->where('organization_id', $platformOrgId)
            ->where('is_super_admin', true)
            ->value('branch_id')
            ?: $org->branches()->value('id');

        $roleId = Role::query()
            ->where('organization_id', $platformOrgId)
            ->where('role_name', 'Platform Operator')
            ->value('id')
            ?: Role::query()->where('role_name', 'Platform Operator')->value('id')
            ?: Role::query()->create([
                'organization_id' => $platformOrgId,
                'role_name' => 'Platform Operator',
                'scope' => 'org',
                'is_active' => true,
            ])->id;

        $plain = PasswordPolicy::normalizeInput((string) $data['password']);
        PasswordPolicy::assertValid($platformOrgId, $plain);

        $user = User::query()->create([
            'organization_id' => $platformOrgId,
            'branch_id' => $branchId,
            'role_id' => $roleId,
            'username' => $data['username'],
            'email' => $data['email'],
            'full_name' => $data['full_name'],
            'password' => Hash::make($plain),
            'is_admin' => false,
            'is_super_admin' => true,
            'platform_permissions' => $permissions,
            'access_scope' => 'org',
            'login_channels' => ['backoffice'],
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'must_change_password' => true,
            'password_changed_at' => null,
            'two_factor_enabled' => false,
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);

        return response()->json([
            'message' => 'Platform admin created. They must enable two-factor authentication on first sign-in.',
            'data' => $this->serialize($user->fresh(), $actor),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $this->assertCanManageAdmins($request->user());
        $user = $this->findPlatformAdmin($id);

        return response()->json(['data' => $this->serialize($user, $request->user())]);
    }

    public function update(Request $request, int $id)
    {
        $actor = $request->user();
        $this->assertCanManageAdmins($actor);
        $user = $this->findPlatformAdmin($id);

        $data = $request->validate([
            'email' => 'sometimes|email|max:191',
            'full_name' => 'sometimes|string|max:191',
            'is_active' => 'sometimes|boolean',
            'password' => array_merge(['sometimes'], PasswordPolicy::validationRules((int) $user->organization_id, confirmed: true)),
            'platform_permissions' => 'sometimes|nullable|array',
            'platform_permissions.*' => 'string|max:64',
            'full_access' => 'sometimes|boolean',
        ]);

        if (array_key_exists('full_access', $data) || array_key_exists('platform_permissions', $data)) {
            $nextPermissions = $this->resolvePermissionsInput($data, $actor, $user);
            if (
                (int) $actor->id === (int) $user->id
                && ! PlatformAdminPermissions::allows($nextPermissions, PlatformAdminPermissions::ADMINS)
            ) {
                throw ValidationException::withMessages([
                    'platform_permissions' => ['You cannot remove your own permission to manage platform admins.'],
                ]);
            }
            if (
                $user->hasUnrestrictedPlatformAccess()
                && ! PlatformAdminPermissions::hasFullAccess($nextPermissions)
                && ! $this->anotherUnrestrictedAdminExists($user->id)
            ) {
                throw ValidationException::withMessages([
                    'platform_permissions' => ['Keep at least one unrestricted platform owner account.'],
                ]);
            }
            $user->platform_permissions = $nextPermissions;
        }

        foreach (['email', 'full_name'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }

        if (array_key_exists('is_active', $data)) {
            if (! (bool) $data['is_active'] && (int) $actor->id === (int) $user->id) {
                throw ValidationException::withMessages([
                    'is_active' => ['You cannot deactivate your own platform admin account.'],
                ]);
            }
            if (
                ! (bool) $data['is_active']
                && $user->hasUnrestrictedPlatformAccess()
                && ! $this->anotherUnrestrictedAdminExists($user->id)
            ) {
                throw ValidationException::withMessages([
                    'is_active' => ['Keep at least one active unrestricted platform owner account.'],
                ]);
            }
            $user->is_active = (bool) $data['is_active'];
        }

        if (! empty($data['password'])) {
            $plain = PasswordPolicy::normalizeInput((string) $data['password']);
            PasswordPolicy::assertValid((int) $user->organization_id, $plain);
            $user->password = Hash::make($plain);
            $user->must_change_password = true;
            $user->password_changed_at = null;
        }

        $user->save();

        return response()->json([
            'message' => 'Platform admin updated.',
            'data' => $this->serialize($user->fresh(), $actor),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $actor = $request->user();
        $this->assertCanManageAdmins($actor);
        $user = $this->findPlatformAdmin($id);

        if ((int) $actor->id === (int) $user->id) {
            throw ValidationException::withMessages([
                'id' => ['You cannot delete your own platform admin account.'],
            ]);
        }

        if ($user->hasUnrestrictedPlatformAccess() && ! $this->anotherUnrestrictedAdminExists($user->id)) {
            throw ValidationException::withMessages([
                'id' => ['Keep at least one unrestricted platform owner account.'],
            ]);
        }

        $user->delete();

        return response()->json(['message' => 'Platform admin removed.']);
    }

    protected function assertCanManageAdmins(?User $actor): void
    {
        if (! $actor || ! PlatformAdminPermissions::userAllows($actor, PlatformAdminPermissions::ADMINS)) {
            abort(403, 'You do not have permission to manage platform administrators.');
        }
    }

    protected function platformOrganizationId(): int
    {
        $code = strtoupper((string) config('erp.platform_company_code', 'PLATFORM'));
        $id = Organization::query()->where('company_code', $code)->value('id');
        if (! $id) {
            abort(503, 'PLATFORM organization is not configured.');
        }

        return (int) $id;
    }

    protected function findPlatformAdmin(int $id): User
    {
        $user = User::query()
            ->where('organization_id', $this->platformOrganizationId())
            ->where('is_super_admin', true)
            ->find($id);
        if (! $user) {
            abort(404, 'Platform admin not found.');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    protected function resolvePermissionsInput(array $data, User $actor, ?User $target = null): ?array
    {
        if (! empty($data['full_access'])) {
            if (! $actor->hasUnrestrictedPlatformAccess()) {
                throw ValidationException::withMessages([
                    'full_access' => ['Only an unrestricted platform owner can grant full access.'],
                ]);
            }

            return null;
        }

        if (! array_key_exists('platform_permissions', $data)) {
            // Creating without permissions → limited empty set unless actor is owner granting full.
            return $target?->platform_permissions ?? [];
        }

        $normalized = PlatformAdminPermissions::normalizeForStorage($data['platform_permissions']);
        if ($normalized === null || PlatformAdminPermissions::hasFullAccess($normalized)) {
            if (! $actor->hasUnrestrictedPlatformAccess()) {
                throw ValidationException::withMessages([
                    'platform_permissions' => ['Only an unrestricted platform owner can grant full access.'],
                ]);
            }

            return null;
        }

        // Limited admins cannot grant areas they do not have.
        if (! $actor->hasUnrestrictedPlatformAccess()) {
            foreach ($normalized as $code) {
                if (! PlatformAdminPermissions::userAllows($actor, $code)) {
                    throw ValidationException::withMessages([
                        'platform_permissions' => ["You cannot grant the \"{$code}\" permission you do not have."],
                    ]);
                }
            }
        }

        return $normalized;
    }

    protected function anotherUnrestrictedAdminExists(int $exceptUserId): bool
    {
        return User::query()
            ->where('organization_id', $this->platformOrganizationId())
            ->where('is_super_admin', true)
            ->where('is_active', true)
            ->where('id', '!=', $exceptUserId)
            ->where(function ($q) {
                $q->whereNull('platform_permissions')
                    ->orWhereJsonContains('platform_permissions', PlatformAdminPermissions::FULL);
            })
            ->exists();
    }

    /** @return array<string, mixed> */
    protected function serialize(User $user, ?User $actor = null): array
    {
        $desc = PlatformAdminPermissions::describeForUser($user);

        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'full_name' => $user->full_name,
            'is_active' => (bool) $user->is_active,
            'is_super_admin' => true,
            'platform_permissions' => $user->platform_permissions,
            'full_access' => $desc['full_access'],
            'areas' => $desc['areas'],
            'two_factor_enabled' => $desc['two_factor_enabled'],
            'two_factor_required' => true,
            'must_change_password' => (bool) $user->must_change_password,
            'created_at' => $user->created_at?->toIso8601String(),
            'is_self' => $actor ? (int) $actor->id === (int) $user->id : false,
        ];
    }
}
