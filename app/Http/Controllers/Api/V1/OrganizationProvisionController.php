<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationProvisioningService;
use Illuminate\Http\Request;

class OrganizationProvisionController extends Controller
{
    public function __construct(
        protected OrganizationProvisioningService $provisioning,
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

        $modules = collect(config('erp.modules', []))
            ->map(fn (array $module, string $key) => [
                'key' => $key,
                'label' => $module['label'] ?? $key,
            ])
            ->values();

        return response()->json([
            'profiles' => $tenantProfiles,
            'modules' => $modules,
            'notes' => [
                'platform_controlled' => 'Module toggles control which areas of the ERP this organization can access (navigation, routes, and features). Only a platform super admin sets these at registration or from Platform → Organizations.',
                'org_settings' => 'Organization managers configure operational preferences (sales checkout options, finance, HR rules, etc.) under Administration → Organization settings — only for modules enabled here.',
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

        $data = $request->validate([
            'deployment_profile' => 'sometimes|in:small_shop,wholesale_retail,distribution',
            'enabled_modules' => 'sometimes|array',
            'enabled_modules.*' => 'boolean',
        ]);

        $moduleKeys = array_keys(config('erp.modules', []));
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
        ];
    }

    /** POST /api/v1/admin/organizations/provision */
    public function store(Request $request)
    {
        $data = $request->validate([
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
        ]);

        $moduleKeys = array_keys(config('erp.modules', []));
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
            'message' => 'Organization created. The manager can sign in with their username and password.',
        ], 201);
    }
}
