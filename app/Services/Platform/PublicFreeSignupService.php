<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\Erp\IndustryRegistry;
use App\Services\Erp\SetupProfileMenuService;
use App\Services\OrganizationProvisioningService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public marketing-site signup onto Package 1 (free plan). No paid subscription is created.
 */
class PublicFreeSignupService
{
    private const TRIAL_APPLICATIONS = [
        'pos',
        'backoffice',
        'distribution',
        'hotel_bar_pos',
        'hospitality_backoffice',
        'spa',
        'driving',
    ];

    public function __construct(
        protected OrganizationProvisioningService $provisioning,
        protected FreePlanAccess $freePlan,
        protected SetupProfileMenuService $setupProfiles,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('erp.allow_public_free_signup', true);
    }

    /**
     * @param  array{
     *   org_name: string,
     *   org_email: string,
     *   primary_tel: string,
     *   industry?: string,
     *   setup_type?: string,
     *   plan_id?: int|string|null,
     *   business_type?: string,
     *   admin_full_name: string,
     *   admin_password: string,
     *   org_address?: string|null,
     * }  $input
     * @return array{
     *   organization: Organization,
     *   manager: User,
     *   company_code: string,
     *   username: string,
     *   industry: string,
     *   business_type: string,
     *   setup_type: string,
     *   setup_type_label: string,
     *   free_plan_name: string,
     *   login_url: string,
     * }
     */
    public function signup(array $input): array
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                'signup' => ['Public free signup is temporarily unavailable. Contact Centrix to get started.'],
            ]);
        }

        $requestedIndustry = strtolower(trim((string) ($input['industry'] ?? '')));
        $setupType = trim((string) ($input['setup_type'] ?? ''));
        $selectedPlan = null;
        if (! empty($input['plan_id'])) {
            $selectedPlan = PlatformPlan::query()->find((int) $input['plan_id']);
            if (
                $selectedPlan === null
                || ! $selectedPlan->is_active
                || (Schema::hasColumn('platform_plans', 'self_serve') && ! $selectedPlan->self_serve)
                || (((float) ($selectedPlan->monthly_price ?? 0)) <= 0
                    && ((float) ($selectedPlan->yearly_price ?? 0)) <= 0)
            ) {
                throw ValidationException::withMessages([
                    'plan_id' => ['Choose an active self-serve package.'],
                ]);
            }
        }
        $setupTypeOption = null;
        if ($setupType !== '') {
            foreach ($this->setupTypeOptions() as $option) {
                if ($option['id'] === $setupType) {
                    $setupTypeOption = $option;
                    break;
                }
            }
            if ($setupTypeOption === null) {
                throw ValidationException::withMessages([
                    'setup_type' => ['Choose a valid setup type.'],
                ]);
            }
            $industry = $setupTypeOption['industry'];
            if ($requestedIndustry !== '' && $requestedIndustry !== $industry) {
                throw ValidationException::withMessages([
                    'setup_type' => ['Choose a setup type for the selected industry.'],
                ]);
            }
            $profile = $setupType;
        } else {
            $industry = $requestedIndustry;
            $definition = IndustryRegistry::definition($industry);
            if ($definition === null) {
                throw ValidationException::withMessages([
                    'industry' => ['Choose a valid industry product.'],
                ]);
            }

            $profile = trim((string) ($definition['default_profile'] ?? ''));
            if ($profile === '' || ! is_array(config("erp.profiles.{$profile}"))) {
                throw ValidationException::withMessages([
                    'industry' => ['This industry is not ready for self-serve signup yet.'],
                ]);
            }
            $setupTypeOption = [
                'id' => $profile,
                'label' => (string) (config("erp.profiles.{$profile}.label") ?? $profile),
                'industry' => $industry,
            ];
        }

        if ($selectedPlan !== null) {
            $planIndustry = (string) ($selectedPlan->industry ?: 'commerce');
            if ($planIndustry !== $industry) {
                throw ValidationException::withMessages([
                    'plan_id' => ['Choose a setup type for the selected package.'],
                ]);
            }
            $allowedProfiles = is_array($selectedPlan->setup_profile_keys) ? $selectedPlan->setup_profile_keys : [];
            $trialProfiles = is_array($selectedPlan->trial_profile_keys) ? $selectedPlan->trial_profile_keys : [];
            if (
                ($allowedProfiles !== [] && ! in_array($profile, $allowedProfiles, true))
                || ($trialProfiles !== [] && ! in_array($profile, $trialProfiles, true))
            ) {
                throw ValidationException::withMessages([
                    'setup_type' => ['Choose a setup type available with the selected package.'],
                ]);
            }
        }

        $businessType = trim((string) ($input['business_type'] ?? ''));
        if ($businessType === '') {
            $businessType = IndustryRegistry::labelForIndustry($industry);
        }
        if ($setupTypeOption !== null) {
            $allowedBusinessTypes = is_array($setupTypeOption['business_types'] ?? null)
                ? $setupTypeOption['business_types']
                : [];
            if (
                $allowedBusinessTypes !== []
                && $businessType !== 'Other'
                && ! in_array($businessType, $allowedBusinessTypes, true)
            ) {
                throw ValidationException::withMessages([
                    'setup_type' => ['Choose a setup type that matches this type of business.'],
                ]);
            }
        }

        $orgName = trim((string) $input['org_name']);
        $orgEmail = strtolower(trim((string) $input['org_email']));
        $adminName = trim((string) $input['admin_full_name']);
        $password = (string) $input['admin_password'];
        $phone = trim((string) $input['primary_tel']);
        $address = trim((string) ($input['org_address'] ?? ''));
        if ($address === '') {
            $address = 'Kenya';
        }

        $companyCode = $this->allocateCompanyCode($orgName);
        $username = $this->allocateUsername($orgEmail);

        $result = $this->provisioning->provision([
            'company_code' => $companyCode,
            'org_name' => $orgName,
            'org_email' => $orgEmail,
            'primary_tel' => $phone,
            'org_address' => $address,
            'deployment_profile' => $profile,
            'business_type' => $businessType,
            'registration_source' => 'self_registered',
            'admin_username' => $username,
            'admin_email' => $orgEmail,
            'admin_password' => $password,
            'admin_full_name' => $adminName,
        ]);

        $loginUrl = rtrim((string) config('erp.frontend_url'), '/').'/login?'.http_build_query([
            'company_code' => $companyCode,
            'username' => $username,
            'from' => 'free-signup',
        ]);

        return [
            'organization' => $result['organization'],
            'manager' => $result['manager'],
            'company_code' => $companyCode,
            'username' => $username,
            'industry' => $industry,
            'business_type' => $businessType,
            'setup_type' => $profile,
            'setup_type_label' => $setupTypeOption['label'],
            'free_plan_name' => $this->freePlan->name(),
            'login_url' => $loginUrl,
        ];
    }

    /** @return list<array{id: string, label: string, description: string}> */
    public function industryOptions(): array
    {
        $out = [];
        foreach (IndustryRegistry::optionsPayload() as $row) {
            $profile = (string) ($row['default_profile'] ?? '');
            if ($profile === '' || ! is_array(config("erp.profiles.{$profile}"))) {
                continue;
            }
            $out[] = [
                'id' => (string) $row['id'],
                'label' => (string) $row['label'],
                'description' => (string) ($row['description'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, label: string, industry: string, industry_label: string, business_types: list<string>}>
     */
    public function setupTypeOptions(): array
    {
        $availableIndustries = array_fill_keys(
            array_column($this->industryOptions(), 'id'),
            true,
        );
        $out = [];
        foreach ($this->setupProfiles->all() as $profile) {
            $key = (string) ($profile['key'] ?? '');
            $industry = (string) ($profile['industry'] ?? '');
            if (
                $key === ''
                || ! isset($availableIndustries[$industry])
                || (! ($profile['custom'] ?? false)
                    && ! in_array($key, IndustryRegistry::definition($industry)['profile_keys'] ?? [], true))
            ) {
                continue;
            }
            if (! collect(self::TRIAL_APPLICATIONS)->contains(
                fn (string $application) => ($profile['applications'][$application] ?? false) === true,
            )) {
                continue;
            }
            $out[] = [
                'id' => $key,
                'label' => (string) ($profile['label'] ?? $key),
                'industry' => $industry,
                'industry_label' => IndustryRegistry::labelForIndustry($industry),
                'business_types' => array_values(array_map(
                    'strval',
                    is_array($profile['business_types'] ?? null) ? $profile['business_types'] : [],
                )),
            ];
        }

        return $out;
    }

    /** @return list<array{value: string, label: string, industry: string}> */
    public function businessTypeOptions(): array
    {
        return $this->setupProfiles->businessTypeCatalog();
    }

    protected function allocateCompanyCode(string $orgName): string
    {
        $slug = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::slug($orgName, ''))));
        $base = substr($slug !== '' ? $slug : 'ORG', 0, 8);
        for ($i = 0; $i < 40; $i++) {
            $suffix = $i === 0 ? '' : (string) random_int(10, 99);
            $code = substr($base.$suffix, 0, 45);
            if ($code === '') {
                $code = 'ORG'.random_int(100, 999);
            }
            if (! Organization::query()->whereRaw('UPPER(company_code) = ?', [strtoupper($code)])->exists()) {
                return strtoupper($code);
            }
        }

        return 'ORG'.strtoupper(Str::random(6));
    }

    protected function allocateUsername(string $email): string
    {
        $local = strtolower((string) strstr($email, '@', true));
        $local = preg_replace('/[^a-z0-9._-]/', '', $local ?? '') ?? '';
        $base = substr($local !== '' ? $local : 'admin', 0, 40);
        for ($i = 0; $i < 40; $i++) {
            $candidate = $i === 0 ? $base : substr($base, 0, 36).$i;
            if (! User::query()->where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'admin'.strtolower(Str::random(6));
    }
}
