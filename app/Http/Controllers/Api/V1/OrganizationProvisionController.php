<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationPlatformConfigService;
use App\Services\OrganizationProvisioningService;
use App\Services\Erp\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Services\Erp\OrderWorkflowService;

class OrganizationProvisionController extends Controller
{
    public function __construct(
        protected OrganizationProvisioningService $provisioning,
        protected OrganizationPlatformConfigService $platformConfig,
    ) {}

    /** GET /api/v1/admin/organizations — list tenants (super admin only) */
    public function index()
    {
        return response()->json([
            'data' => Organization::query()
                ->where('company_code', '!=', config('erp.platform_company_code', 'PLATFORM'))
                ->orderBy('org_name')
                ->get(['id', 'company_code', 'org_name', 'org_email', 'deployment_profile', 'created_at']),
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
            ])
            ->values();

        $modules = collect(ModuleRegistry::optionsPayload())->values();

        $navGroups = collect(config('erp.nav_groups', []))
            ->map(fn (array $group, string $label) => [
                'label' => $label,
                'description' => $group['description'] ?? '',
                'sort' => $group['sort'] ?? 999,
            ])
            ->sortBy('sort')
            ->values();

        return response()->json([
            'profiles' => $tenantProfiles,
            'modules' => $modules,
            'nav_groups' => $navGroups,
            'default_sales_platform' => $this->platformConfig->defaultSalesPlatformConfig('wholesale_retail'),
            'notes' => [
                'platform_controlled' => 'Module toggles match sidebar areas (Sales, HR, Inventory, etc.). Only the platform super admin sets these at registration or under Platform → Organizations.',
                'sales_platform' => 'Checkout vs save order, order workflow, and stock timing are configured by the platform super admin — not the organization manager.',
                'org_settings' => 'Organization managers configure day-to-day preferences (payment fields, receipts, SMTP, M-Pesa, etc.) under Administration → Organization settings.',
            ],
        ]);
    }

    /** GET /api/v1/admin/organizations/{organization} */
    public function show(int $organization)
    {
        $org = $this->findTenantOrganization($organization);

        return response()->json($this->organizationPayload($org));
    }

    /** PATCH /api/v1/admin/organizations/{organization} */
    public function update(Request $request, int $organization)
    {
        $org = $this->findTenantOrganization($organization);

        $data = $request->validate(array_merge(
            $this->salesPlatformRules(),
            [
                'deployment_profile' => 'sometimes|in:small_shop,wholesale_retail,distribution',
                'enabled_modules' => 'sometimes|array',
                'enabled_modules.*' => 'boolean',
            ],
        ));

        $moduleKeys = ModuleRegistry::keys();
        if (isset($data['enabled_modules'])) {
            $unknown = array_diff(array_keys($data['enabled_modules']), $moduleKeys);
            if ($unknown !== []) {
                return response()->json([
                    'message' => 'Unknown module keys: '.implode(', ', $unknown),
                ], 422);
            }
        }

        if (array_key_exists('deployment_profile', $data)) {
            $org->deployment_profile = $data['deployment_profile'];
        }
        if (array_key_exists('enabled_modules', $data)) {
            $org->enabled_modules = $this->provisioning->normalizeEnabledModules($data['enabled_modules']);
        }
        $org->save();

        if (array_key_exists('enabled_modules', $data)) {
            $this->provisioning->syncModuleSettingsFromEnabledModules($org);
        }

        if (array_key_exists('sales_platform', $data) && is_array($data['sales_platform'])) {
            $this->platformConfig->applySalesPlatformConfig($org, $data['sales_platform']);
        }

        return response()->json($this->organizationPayload($org->fresh()));
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
            'organization' => $org->only([
                'id', 'company_code', 'org_name', 'org_email', 'primary_tel', 'org_address',
                'org_pin', 'vat_regno', 'deployment_profile', 'enabled_modules', 'created_at',
            ]),
            'effective_modules' => $gate->allModules(),
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
                'deployment_profile' => 'required|in:small_shop,wholesale_retail,distribution',
                'enabled_modules' => 'sometimes|array',
                'enabled_modules.*' => 'boolean',
                'admin_username' => 'required|string|max:50',
                'admin_email' => 'required|email|max:255',
                'admin_password' => 'required|string|min:6',
                'admin_full_name' => 'required|string|max:200',
            ],
            $this->salesPlatformRules(),
        ));

        $moduleKeys = ModuleRegistry::keys();
        if (isset($data['enabled_modules'])) {
            $unknown = array_diff(array_keys($data['enabled_modules']), $moduleKeys);
            if ($unknown !== []) {
                return response()->json([
                    'message' => 'Unknown module keys: '.implode(', ', $unknown),
                ], 422);
            }
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
    protected function salesPlatformRules(): array
    {
        $statusRule = Rule::in(OrderWorkflowService::ALL_STATUSES);

        return [
            'sales_platform' => 'sometimes|array',
            'sales_platform.show_checkout_on_create_order' => 'sometimes|boolean',
            'sales_platform.enable_mobile_orders' => 'sometimes|boolean',
            'sales_platform.enable_pos_orders' => 'sometimes|boolean',
            'sales_platform.stock_deduct_on' => 'sometimes|in:order_completed,trip_load,trip_depart',
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
}
