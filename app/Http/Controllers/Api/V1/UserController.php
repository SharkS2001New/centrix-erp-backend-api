<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Models\UserMembership;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserLoginChannelService;
use App\Services\Auth\UserLoginService;
use App\Services\Auth\UserPermissionService;
use App\Services\Auth\UsernameValidator;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return User::class;
    }

    protected function scopesByBranch(): bool
    {
        return true;
    }

    public function store(Request $request)
    {
        $orgId = (int) ($request->user()?->organization_id ?? 0);
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $rules['password'] = PasswordPolicy::validationRules($orgId ?: null, confirmed: false);
        $rules['access_scope'] = 'required|in:org,branch';
        $rules['login_channels'] = 'sometimes|array|min:1';
        $rules['login_channels.*'] = 'in:backoffice,pos,mobile';
        $data = $request->validate($rules);
        $data = $this->access()->validateAccessScope($data, (bool) ($data['is_admin'] ?? false));
        if (! array_key_exists('login_channels', $data)) {
            $data['login_channels'] = app(UserLoginChannelService::class)->defaultChannels();
        }
        $data = $this->normalizeLoginChannels($data);
        $data['organization_id'] = $request->user()->organization_id;
        app(UsernameValidator::class)->assertUniqueInOrganization(
            (int) $data['organization_id'],
            (string) $data['username'],
        );
        if (! empty($data['password'])) {
            PasswordPolicy::assertValid($orgId ?: null, (string) $data['password']);
            $data['password'] = Hash::make($data['password']);
        }
        $model = User::create($data);

        return response()->json($model, 201);
    }

    public function update(Request $request, string $id)
    {
        $model = $this->findOrgUser($id);
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $rules['access_scope'] = 'sometimes|in:org,branch';
        $rules['login_channels'] = 'sometimes|array|min:1';
        $rules['login_channels.*'] = 'in:backoffice,pos,mobile';
        $data = $request->validate($rules);
        if (isset($data['access_scope']) || array_key_exists('branch_id', $data)) {
            $merged = array_merge($model->only(['access_scope', 'branch_id', 'is_admin']), $data);
            $data = array_merge($data, $this->access()->validateAccessScope($merged, (bool) ($merged['is_admin'] ?? false)));
        }
        if (array_key_exists('login_channels', $data)) {
            $data = $this->normalizeLoginChannels($data);
        }
        if (! empty($data['username'])) {
            app(UsernameValidator::class)->assertUniqueInOrganization(
                (int) $model->organization_id,
                (string) $data['username'],
                ignoreUserId: (int) $model->id,
            );
        }
        unset($data['organization_id']);
        if (! empty($data['password'])) {
            PasswordPolicy::assertValid((int) $model->organization_id, (string) $data['password']);
            $data['password'] = Hash::make($data['password']);
        }
        if (array_key_exists('is_active', $data) && $data['is_active']) {
            app(UserLoginService::class)->assertCanEnableLogin($model);
        }
        $deactivate = array_key_exists('is_active', $data) && ! $data['is_active'];
        $model->update($data);
        if ($deactivate) {
            $model = app(UserLoginService::class)->disableLogin($model);
        }

        return response()->json($model);
    }

    public function destroy(Request $request, string $id)
    {
        $model = $this->findOrgUser($id);
        $authUser = $request->user();

        if ($authUser && (int) $authUser->id === (int) $model->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        $model->forceFill(['deleted_by' => $authUser?->id])->save();
        app(UserLoginService::class)->disableLogin($model);
        $model->delete();

        return response()->json([
            'message' => 'User archived (soft deleted). Sales and activity history are retained.',
        ]);
    }

    public function permissions(string $id)
    {
        PermissionMatrixService::ensure();

        return response()->json(
            app(UserPermissionService::class)->describeForUser($this->findOrgUser($id)),
        );
    }

    public function syncPermissions(Request $request, string $id)
    {
        $user = $this->findOrgUser($id);

        if ($user->is_admin) {
            throw ValidationException::withMessages([
                'user' => ['Administrators have all permissions; per-user overrides do not apply.'],
            ]);
        }

        $data = $request->validate([
            'granted_permission_ids' => 'array',
            'granted_permission_ids.*' => 'integer|exists:permissions,id',
            'denied_permission_ids' => 'array',
            'denied_permission_ids.*' => 'integer|exists:permissions,id',
        ]);

        app(UserPermissionService::class)->syncOverrides(
            (int) $user->id,
            $data['granted_permission_ids'] ?? [],
            $data['denied_permission_ids'] ?? [],
        );

        return $this->permissions($id);
    }

    /** POST /users/{id}/memberships — grant access to another organization (same credentials). */
    public function addMembership(Request $request, string $id)
    {
        $source = $this->findOrgUser($id);
        $data = $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'role_id' => 'required|integer|exists:roles,id',
            'username' => 'required|string|max:50',
            'access_scope' => 'required|in:org,branch',
            'is_admin' => 'sometimes|boolean',
        ]);
        $data = $this->access()->validateAccessScope($data, (bool) ($data['is_admin'] ?? false));

        app(UsernameValidator::class)->assertUniqueInOrganization(
            (int) $data['organization_id'],
            (string) $data['username'],
            ignoreUserId: (int) $source->id,
        );

        if ((int) $data['organization_id'] === (int) $source->organization_id) {
            throw ValidationException::withMessages([
                'organization_id' => ['User already belongs to this organization.'],
            ]);
        }

        $membership = UserMembership::create([
            'user_id' => $source->id,
            'organization_id' => $data['organization_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'role_id' => $data['role_id'],
            'username' => $data['username'],
            'access_scope' => $data['access_scope'],
            'is_admin' => (bool) ($data['is_admin'] ?? false),
            'is_active' => true,
        ]);

        return response()->json($membership->load('organization', 'branch', 'role'), 201);
    }

    protected function findOrgUser(string $id): User
    {
        return User::query()
            ->where('id', $id)
            ->where('organization_id', request()->user()->organization_id)
            ->firstOrFail();
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeLoginChannels(array $data): array
    {
        if (! array_key_exists('login_channels', $data)) {
            return $data;
        }

        $channels = app(UserLoginChannelService::class)->normalize($data['login_channels']);
        $data['login_channels'] = $channels;
        $data['is_mobile_user'] = app(UserLoginChannelService::class)->syncLegacyMobileFlag($channels);

        return $data;
    }
}
