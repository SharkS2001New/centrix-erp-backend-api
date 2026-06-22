<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Models\UserMembership;
use App\Models\Organization;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\UserAccountGuard;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserDeletionService;
use App\Services\Auth\UserLoginChannelService;
use App\Services\Auth\UserLoginChannelPolicy;
use App\Services\Auth\UserMobileOrderScopeService;
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
        $orgId = (int) ($this->access()->organizationId($request->user(), $request) ?? 0);
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $rules['password'] = PasswordPolicy::validationRules($orgId ?: null, confirmed: false);
        $rules['access_scope'] = 'required|in:org,branch';
        $rules['login_channels'] = 'sometimes|array|min:1';
        $rules['login_channels.*'] = 'in:backoffice,pos,mobile';
        $rules['assigned_route_id'] = 'nullable|integer|exists:routes,id';
        $rules['must_change_password'] = 'sometimes|boolean';
        $data = $request->validate($rules);
        $data = $this->access()->validateAccessScope($data, (bool) ($data['is_admin'] ?? false));
        if (! array_key_exists('login_channels', $data)) {
            $data['login_channels'] = app(UserLoginChannelService::class)->defaultChannels();
        }
        app(UserLoginChannelPolicy::class)->assertAllowedForOrganization(
            Organization::findOrFail($orgId),
            $data['login_channels'],
        );
        $data = $this->normalizeLoginChannels($data);
        $data = app(UserMobileOrderScopeService::class)->normalizeUserAttributes($data);
        $data['organization_id'] = $this->access()->organizationId($request->user(), $request);
        app(UsernameValidator::class)->assertUniqueInOrganization(
            (int) $data['organization_id'],
            (string) $data['username'],
        );
        if (! empty($data['password'])) {
            PasswordPolicy::assertValid($orgId ?: null, (string) $data['password']);
            $data['password'] = Hash::make($data['password']);
            $data['must_change_password'] = (bool) ($data['must_change_password'] ?? true);
        } else {
            unset($data['must_change_password']);
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
        $rules['assigned_route_id'] = 'nullable|integer|exists:routes,id';
        $rules['must_change_password'] = 'sometimes|boolean';
        $data = $request->validate($rules);
        if (isset($data['access_scope']) || array_key_exists('branch_id', $data)) {
            $merged = array_merge($model->only(['access_scope', 'branch_id', 'is_admin']), $data);
            $data = array_merge($data, $this->access()->validateAccessScope($merged, (bool) ($merged['is_admin'] ?? false)));
        }
        if (array_key_exists('login_channels', $data)) {
            app(UserLoginChannelPolicy::class)->assertAllowedForOrganization(
                Organization::findOrFail((int) $model->organization_id),
                $data['login_channels'],
            );
            $data = $this->normalizeLoginChannels($data);
        }
        if (array_key_exists('mobile_order_scope', $data)
            || array_key_exists('assigned_route_id', $data)
            || array_key_exists('login_channels', $data)) {
            $merged = array_merge($model->only(['login_channels', 'mobile_order_scope', 'assigned_route_id']), $data);
            $data = array_merge($data, app(UserMobileOrderScopeService::class)->normalizeUserAttributes($merged));
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
            $data['must_change_password'] = array_key_exists('must_change_password', $data)
                ? (bool) $data['must_change_password']
                : true;
            $model->tokens()->delete();
        } else {
            unset($data['must_change_password']);
        }
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            app(UserAccountGuard::class)->assertCanDisableLogin($model, $request->user());
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

        app(UserAccountGuard::class)->assertCanDelete($model, $authUser);

        $result = app(UserDeletionService::class)->delete($model, $authUser);

        return response()->json([
            'message' => $result['message'],
            'mode' => $result['mode'],
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
        $orgId = $this->access()->organizationId(request()->user(), request());

        return User::query()
            ->where('id', $id)
            ->where('organization_id', $orgId)
            ->firstOrFail();
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request)->whereNull('deleted_at');

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('full_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderBy('full_name')->paginate($perPage));
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeLoginChannels(array $data): array
    {
        if (! array_key_exists('login_channels', $data)) {
            return $data;
        }

        $orgId = (int) ($data['organization_id'] ?? request()->user()?->organization_id ?? 0);
        $organization = $orgId ? Organization::find($orgId) : null;
        $channels = $organization
            ? app(UserLoginChannelPolicy::class)->sanitizeForOrganization($organization, $data['login_channels'])
            : app(UserLoginChannelService::class)->normalize($data['login_channels']);
        $data['login_channels'] = $channels;
        $data['is_mobile_user'] = app(UserLoginChannelService::class)->syncLegacyMobileFlag($channels);

        return $data;
    }
}
