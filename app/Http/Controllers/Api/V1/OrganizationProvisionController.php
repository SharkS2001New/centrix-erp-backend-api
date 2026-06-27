<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\Erp\WorkspaceSessionLabel;
use App\Services\OrganizationDeprovisioningService;
use App\Services\Organization\OrganizationCompanyCodeService;
use App\Services\OrganizationPlatformConfigService;
use App\Services\OrganizationProvisioningService;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\UserLoginChannelPolicy;
use App\Services\Auth\UserLoginService;
use App\Services\Auth\UserDeletionService;
use App\Services\Auth\UsernameValidator;
use App\Services\Erp\ModuleRegistry;
use App\Services\Erp\ApplicationProvisioner;
use App\Services\Ai\AiSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\Erp\OrderWorkflowService;

class OrganizationProvisionController extends Controller
{
    public function __construct(
        protected OrganizationProvisioningService $provisioning,
        protected OrganizationPlatformConfigService $platformConfig,
        protected ApplicationProvisioner $applications,
        protected OrganizationDeprovisioningService $deprovisioning,
        protected OrganizationCompanyCodeService $companyCodes,
    ) {}

    /** GET /api/v1/admin/organizations — list tenants (super admin only) */
    public function index()
    {
        $platformCode = config('erp.platform_company_code', 'PLATFORM');
        $organizations = Organization::query()
            ->where('company_code', '!=', $platformCode)
            ->orderBy('org_name')
            ->get(['id', 'company_code', 'org_name', 'org_email', 'deployment_profile', 'is_active', 'created_at', 'enabled_modules']);

        $data = $organizations->map(function (Organization $org) {
            $gate = app(\App\Services\Erp\CapabilityGate::class)->forOrganization($org);
            $effectiveModules = $gate->allModules();

            return array_merge($org->only([
                'id', 'company_code', 'org_name', 'org_email', 'deployment_profile', 'is_active', 'created_at',
            ]), [
                'administration_enabled' => (bool) ($effectiveModules['admin'] ?? false),
            ]);
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    /** GET /api/v1/admin/organizations/provision-options — module presets for provisioning */
    public function options()
    {
        $tenantProfiles = collect(config('erp.profiles', []))
            ->except('platform')
            ->map(fn (array $profile, string $key) => [
                'key' => $key,
                'label' => $profile['label'] ?? $key,
                'modules' => $profile['modules'] ?? [],
                'applications' => $this->applications->applicationsFromProfileModules($profile['modules'] ?? []),
            ])
            ->values();

        return response()->json([
            'applications' => $this->applications->optionsPayload(),
            'profiles' => $tenantProfiles,
            'modules' => collect(ModuleRegistry::optionsPayload())->values(),
            'default_sales_platform' => $this->platformConfig->defaultSalesPlatformConfig('wholesale_retail'),
            'notes' => [
                'applications' => 'Enable or disable the six tenant applications (External ERP, Backoffice, Accounting, Human Resources, Distribution, Administration). The API stores the underlying module keys automatically.',
                'sales_platform' => 'Checkout vs save order, order workflow, and stock timing are configured by the platform super admin — not the organization manager.',
                'org_settings' => 'Organization managers configure day-to-day preferences (payment fields, receipts, SMTP, M-Pesa, etc.) under Administration → Organization settings when Administration is enabled.',
            ],
        ]);
    }

    /** GET /api/v1/admin/organizations/{organization} */
    public function show(int $organization)
    {
        $org = $this->findTenantOrganization($organization);

        return response()->json($this->organizationPayload($org));
    }

    /** PATCH /api/v1/admin/organizations/{organization}/company-code */
    public function renameCompanyCode(Request $request, int $organization)
    {
        $org = $this->findTenantOrganization($organization);

        $data = $request->validate([
            'company_code' => 'required|string|max:45',
        ]);

        $org = $this->companyCodes->rename($org, $data['company_code']);

        return response()->json([
            'organization' => $org->toProfileArray(),
            'message' => 'Company code updated. Previous codes remain valid for sign-in.',
        ]);
    }

    /** PATCH /api/v1/admin/organizations/{organization} */
    public function update(Request $request, int $organization)
    {
        $org = $this->findTenantOrganization($organization);

        $data = $request->validate(array_merge(
            $this->salesPlatformRules(),
            $this->tenantProfileRules(),
            $this->applicationRules(),
            [
                'is_active' => 'sometimes|boolean',
                'enabled_modules' => 'sometimes|array',
                'enabled_modules.*' => 'boolean',
            ],
        ));

        if ($resolvedModules = $this->resolveEnabledModulesInput($data, $org)) {
            $data['enabled_modules'] = $resolvedModules;
        } elseif (isset($data['enabled_modules'])) {
            $data['enabled_modules'] = $this->validateEnabledModulesMap($data['enabled_modules']);
        }

        if (array_key_exists('deployment_profile', $data)) {
            $org->deployment_profile = $data['deployment_profile'];
        }
        foreach (['org_name', 'org_email', 'primary_tel', 'secondary_tel', 'addn_tel1', 'addn_tel2', 'org_address', 'org_pin', 'vat_regno'] as $field) {
            if (array_key_exists($field, $data)) {
                $org->{$field} = $data[$field];
            }
        }
        if (array_key_exists('enabled_modules', $data)) {
            $salesPlatform = is_array($data['sales_platform'] ?? null)
                ? $data['sales_platform']
                : $this->platformConfig->salesPlatformConfigForOrganization($org);
            $reconciled = $this->platformConfig->reconcileEnabledModules(
                $org,
                $data['enabled_modules'],
                $salesPlatform,
            );
            $org->enabled_modules = $this->provisioning->normalizeEnabledModules($reconciled);
        }
        if (array_key_exists('is_active', $data)) {
            $org->is_active = (bool) $data['is_active'];
            if (! $org->is_active) {
                User::query()
                    ->where('organization_id', $org->id)
                    ->each(fn (User $user) => app(UserLoginService::class)->disableLogin($user));
            }
        }
        $org->save();

        if (array_key_exists('enabled_modules', $data)) {
            $this->provisioning->syncModuleSettingsFromEnabledModules($org);
        }

        if (array_key_exists('sales_platform', $data) && is_array($data['sales_platform'])) {
            $this->platformConfig->applySalesPlatformConfig($org, $data['sales_platform']);
            if (array_key_exists('enable_mobile_orders', $data['sales_platform'])) {
                $modules = $org->enabled_modules ?? [];
                $modules = $this->platformConfig->reconcileEnabledModules($org, $modules, $data['sales_platform']);
                $org->enabled_modules = $this->provisioning->normalizeEnabledModules($modules);
                $org->save();
                $this->provisioning->syncModuleSettingsFromEnabledModules($org);
            }
        }

        return response()->json($this->organizationPayload($org->fresh()));
    }

    /** DELETE /api/v1/admin/organizations/{organization} */
    public function destroy(Request $request, int $organization)
    {
        $org = $this->findTenantOrganization($organization);

        $data = $request->validate([
            'password' => 'required|string',
            'confirmation' => 'required|string|max:200',
        ]);

        if (trim($data['confirmation']) !== trim((string) $org->org_name)) {
            throw ValidationException::withMessages([
                'confirmation' => ['Type the organization name exactly to confirm deletion.'],
            ]);
        }

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ['Incorrect password.'],
            ]);
        }

        $this->deprovisioning->delete($org);

        return response()->json([
            'message' => 'Organization deleted. All users have been signed out and can no longer sign in.',
        ]);
    }

    /** PATCH /api/v1/admin/organizations/{organization}/users/{user} */
    public function updateUser(Request $request, int $organization, int $user)
    {
        $org = $this->findTenantOrganization($organization);
        $model = User::query()
            ->where('organization_id', $org->id)
            ->where('id', $user)
            ->where('is_super_admin', false)
            ->firstOrFail();

        $data = $request->validate([
            'full_name' => 'sometimes|string|max:200',
            'username' => 'sometimes|string|max:50',
            'email' => 'sometimes|email|max:255',
            'password' => 'sometimes|string|min:6',
            'is_active' => 'sometimes|boolean',
            'must_change_password' => 'sometimes|boolean',
        ]);

        if (array_key_exists('username', $data) && $data['username'] !== null) {
            app(UsernameValidator::class)->assertUniqueInOrganization(
                (int) $org->id,
                (string) $data['username'],
                ignoreUserId: (int) $model->id,
            );
        }

        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $emailTaken = User::query()
                ->where('organization_id', $org->id)
                ->where('email', $data['email'])
                ->where('id', '!=', $model->id)
                ->whereNull('deleted_at')
                ->exists();
            if ($emailTaken) {
                throw ValidationException::withMessages([
                    'email' => ['This email is already used by another user in this organization.'],
                ]);
            }
        }

        foreach (['full_name', 'username', 'email'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $model->{$field} = $data[$field];
            }
        }

        if (! empty($data['password'])) {
            PasswordPolicy::assertValid((int) $org->id, (string) $data['password']);
            $model->password = Hash::make($data['password']);
            $model->must_change_password = (bool) ($data['must_change_password'] ?? true);
            $model->tokens()->delete();
        }

        if (array_key_exists('is_active', $data)) {
            $model->is_active = (bool) $data['is_active'];
            if (! $model->is_active) {
                app(UserLoginService::class)->disableLogin($model);
            }
        }

        $model->save();

        return response()->json([
            'user' => $model->only([
                'id', 'username', 'email', 'full_name', 'is_admin', 'login_channels', 'is_active', 'must_change_password',
            ]),
        ]);
    }

    /** DELETE /api/v1/admin/organizations/{organization}/users/{user} */
    public function deleteUser(Request $request, int $organization, int $user)
    {
        $org = $this->findTenantOrganization($organization);
        $model = User::query()
            ->where('organization_id', $org->id)
            ->where('id', $user)
            ->where('is_super_admin', false)
            ->firstOrFail();

        $result = app(UserDeletionService::class)->delete($model, $request->user());

        return response()->json([
            'message' => $result['message'],
            'mode' => $result['mode'],
        ]);
    }

    /** GET /api/v1/admin/organizations/{organization}/users */
    public function listUsers(int $organization)
    {
        $org = $this->findTenantOrganization($organization);

        $users = User::query()
            ->where('organization_id', $org->id)
            ->where('is_super_admin', false)
            ->orderBy('full_name')
            ->orderBy('username')
            ->get([
                'id', 'username', 'email', 'full_name', 'is_admin',
                'login_channels', 'is_active', 'must_change_password', 'last_login', 'created_at',
            ]);

        $userIds = $users->pluck('id');
        $sessions = $userIds->isEmpty()
            ? collect()
            : DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->orderByDesc('last_used_at')
                ->get(['id', 'tokenable_id', 'login_channel', 'active_workspace_id', 'name', 'last_used_at', 'created_at']);

        $sessionsByUser = $sessions->groupBy('tokenable_id');

        $data = $users->map(function (User $user) use ($sessionsByUser) {
            $activeLogins = ($sessionsByUser->get($user->id) ?? collect())->map(fn ($token) => [
                'id' => $token->id,
                'channel' => $token->login_channel,
                'active_workspace_id' => $token->active_workspace_id,
                'active_workspace_label' => WorkspaceSessionLabel::for(
                    $token->active_workspace_id,
                    $token->login_channel,
                ),
                'device' => $token->name,
                'last_used_at' => $token->last_used_at,
                'signed_in_at' => $token->created_at,
            ])->values();

            return [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'full_name' => $user->full_name,
                'is_admin' => $user->is_admin,
                'login_channels' => $user->login_channels,
                'is_active' => $user->is_active,
                'must_change_password' => $user->must_change_password,
                'last_login' => $user->last_login,
                'created_at' => $user->created_at,
                'active_login_count' => $activeLogins->count(),
                'active_logins' => $activeLogins,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /** POST /api/v1/admin/organizations/{organization}/users */
    public function createUser(Request $request, int $organization)
    {
        $org = $this->findTenantOrganization($organization);

        $data = $request->validate([
            'full_name' => 'required|string|max:200',
            'username' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
            'is_admin' => 'sometimes|boolean',
            'login_channels' => 'sometimes|array|min:1',
            'login_channels.*' => 'in:backoffice,pos,mobile',
        ]);

        app(UsernameValidator::class)->assertUniqueInOrganization(
            (int) $org->id,
            (string) $data['username'],
        );

        app(UserLoginChannelPolicy::class)->assertAllowedForOrganization(
            $org,
            $data['login_channels'] ?? ['backoffice', 'pos'],
        );

        $user = $this->provisioning->createOrganizationUser($org, $data);

        return response()->json([
            'user' => $user->only([
                'id', 'username', 'email', 'full_name', 'is_admin', 'login_channels', 'is_active',
            ]),
            'message' => 'User created. Share credentials securely with the sign-in URL and company code.',
        ], 201);
    }

    protected function findTenantOrganization(int $id): Organization
    {
        $platformCode = config('erp.platform_company_code', 'PLATFORM');

        return Organization::query()
            ->where('id', $id)
            ->where('company_code', '!=', $platformCode)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    protected function organizationPayload(Organization $org): array
    {
        $gate = app(\App\Services\Erp\CapabilityGate::class)->forOrganization($org);

        return [
            'organization' => $org->toProfileArray(),
            'applications' => $this->applications->applicationsFromEnabledModules($gate->allModules()),
            'effective_modules' => $gate->allModules(),
            'capabilities' => array_merge([
                'modules' => $gate->allModules(),
                'module_settings' => $org->module_settings ?? [],
                'screen_lock_minutes' => \App\Services\Auth\SecuritySettingsResolver::forGate($gate)['screen_lock_minutes'],
                'session_idle_minutes' => \App\Services\Auth\SecuritySettingsResolver::forGate($gate)['session_idle_minutes'],
                'mobile_orders_enabled' => $gate->mobileSalesEnabled(),
                'platform_mpesa_stk_enabled' => $gate->mpesaStkPlatformEnabled(),
                'platform_kra_integration_enabled' => $gate->kraIntegrationPlatformEnabled(),
                'platform_ai_enabled' => $gate->aiPlatformEnabled(),
                'ai_assistant' => AiSettingsResolver::clientCapabilities($gate),
            ]),
            'sales_platform' => $this->platformConfig->salesPlatformConfigForOrganization($org),
        ];
    }

    /** POST /api/v1/admin/organizations/provision */
    public function store(Request $request)
    {
        $data = $request->validate(array_merge(
            [
                'company_code' => 'required|string|max:45|unique:organizations,company_code',
                'org_name' => 'required|string|max:200',
                'org_email' => 'required|email|max:200',
                'primary_tel' => 'required|string|max:45',
                'org_address' => 'required|string|max:400',
                'org_pin' => 'nullable|string|max:45',
                'vat_regno' => 'nullable|string|max:50',
                'deployment_profile' => ['required', Rule::in($this->tenantDeploymentProfileKeys())],
                'enabled_modules' => 'sometimes|array',
                'enabled_modules.*' => 'boolean',
                'admin_username' => 'required|string|max:50',
                'admin_email' => 'required|email|max:255',
                'admin_password' => 'required|string|min:6',
                'admin_full_name' => 'required|string|max:200',
            ],
            $this->salesPlatformRules(),
            $this->applicationRules(),
        ));

        if ($resolvedModules = $this->resolveEnabledModulesInput($data)) {
            $data['enabled_modules'] = $resolvedModules;
        } elseif (isset($data['enabled_modules'])) {
            $data['enabled_modules'] = $this->validateEnabledModulesMap($data['enabled_modules']);
        }

        $result = $this->provisioning->provision($data);

        return response()->json([
            'organization' => $result['organization'],
            'manager' => $result['manager'],
            'branch' => $result['branch'],
            'message' => 'Organization registered. The manager can sign in with their username and password.',
        ], 201);
    }

    /** @return array<string, mixed> */
    protected function tenantProfileRules(): array
    {
        return [
            'org_name' => 'sometimes|string|max:200',
            'org_email' => 'sometimes|email|max:200',
            'primary_tel' => 'sometimes|string|max:45',
            'secondary_tel' => 'nullable|string|max:45',
            'addn_tel1' => 'nullable|string|max:45',
            'addn_tel2' => 'nullable|string|max:45',
            'org_address' => 'sometimes|string|max:400',
            'org_pin' => 'nullable|string|max:45',
            'vat_regno' => 'nullable|string|max:50',
            'deployment_profile' => ['sometimes', Rule::in($this->tenantDeploymentProfileKeys())],
        ];
    }

    /** @return list<string> */
    protected function tenantDeploymentProfileKeys(): array
    {
        return array_keys(collect(config('erp.profiles', []))->except('platform')->all());
    }

    /** @return array<string, mixed> */
    protected function salesPlatformRules(): array
    {
        $statusRule = Rule::in(OrderWorkflowService::ALL_STATUSES);

        return [
            'sales_platform' => 'sometimes|array',
            'sales_platform.show_checkout_on_create_order' => 'sometimes|boolean',
            'sales_platform.enable_mobile_orders' => 'sometimes|boolean',
            'sales_platform.require_pos_till_float' => 'sometimes|boolean',
            'sales_platform.enable_pos_order_edit' => 'sometimes|boolean',
            'sales_platform.enable_mpesa_stk' => 'sometimes|boolean',
            'sales_platform.enable_kra_integration' => 'sometimes|boolean',
            'sales_platform.enable_ai' => 'sometimes|boolean',
            'sales_platform.stock_deduct_on' => 'sometimes|in:order_created,order_completed,trip_load,trip_depart',
            'sales_platform.order_workflow' => 'sometimes|array',
            'sales_platform.order_workflow.steps' => 'sometimes|array',
            'sales_platform.order_workflow.steps.*.status' => ['required_with:sales_platform.order_workflow.steps', 'string', $statusRule],
            'sales_platform.order_workflow.steps.*.label' => 'sometimes|string|max:60',
            'sales_platform.order_workflow.steps.*.enabled' => 'sometimes|boolean',
            'sales_platform.order_workflow.save_status' => 'sometimes|array',
            'sales_platform.order_workflow.checkout' => 'sometimes|array',
            'sales_platform.order_workflow.deduct_stock_on' => ['sometimes', 'string', $statusRule],
        ];
    }

    /** @return array<string, mixed> */
    protected function applicationRules(): array
    {
        $rules = ['applications' => 'sometimes|array'];
        foreach (ApplicationProvisioner::ids() as $id) {
            $rules["applications.{$id}"] = 'sometimes|boolean';
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool>|null
     */
    protected function resolveEnabledModulesInput(array $data, ?Organization $org = null): ?array
    {
        if (! array_key_exists('applications', $data)) {
            return null;
        }

        $mobileOrders = true;
        if (array_key_exists('enable_mobile_orders', $data['sales_platform'] ?? [])) {
            $mobileOrders = (bool) $data['sales_platform']['enable_mobile_orders'];
        } elseif ($org) {
            $mobileOrders = $this->platformConfig->mobileOrdersEnabledForOrganization($org);
        }

        $applications = $this->applications->sanitizeApplications($data['applications']);

        return $this->applications->enabledModulesFromApplications($applications, $mobileOrders);
    }

    /**
     * @param  array<string, bool>  $enabledModules
     * @return array<string, bool>
     */
    protected function validateEnabledModulesMap(array $enabledModules): array
    {
        $enabledModules = ModuleRegistry::sanitizeModuleMap($enabledModules);
        $unknown = array_diff(array_keys($enabledModules), ModuleRegistry::keys());
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'enabled_modules' => ['Unknown module keys: '.implode(', ', $unknown)],
            ]);
        }

        return $enabledModules;
    }
}
