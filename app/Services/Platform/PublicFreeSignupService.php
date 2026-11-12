<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\Erp\ApplicationProvisioner;
use App\Services\Erp\IndustryRegistry;
use App\Services\Erp\SetupProfileMenuService;
use App\Services\OrganizationPlatformConfigService;
use App\Services\OrganizationProvisioningService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public marketing-site Instant trial signup.
 * Provisions the organisation, then starts a timed trial of the matching self-serve package.
 * When the trial ends the ERP payment screen is required before full access continues.
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
        protected OrganizationLicenseService $licenses,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('erp.allow_public_free_signup', true);
    }

    public function trialDays(?PlatformPlan $plan = null): int
    {
        $fromPlan = $plan !== null
            && Schema::hasColumn('platform_plans', 'trial_days')
            && $plan->trial_days !== null
            ? (int) $plan->trial_days
            : 0;
        if ($fromPlan > 0) {
            return $fromPlan;
        }

        return max(1, (int) config('erp.public_signup_trial_days', 7));
    }

    /** @return array{phone: string, phone_href: string, whatsapp: string, whatsapp_url: string} */
    public function trialSupport(): array
    {
        $cfg = is_array(config('erp.trial_support')) ? config('erp.trial_support') : [];
        $phone = trim((string) ($cfg['phone'] ?? '+254 748 956 677'));
        $phoneHref = trim((string) ($cfg['phone_href'] ?? ''));
        if ($phoneHref === '' && $phone !== '') {
            $phoneHref = 'tel:'.preg_replace('/[^\d+]/', '', $phone);
        }
        $whatsapp = preg_replace('/\D+/', '', (string) ($cfg['whatsapp'] ?? '254748956677')) ?: '254748956677';
        $message = trim((string) ($cfg['whatsapp_message'] ?? 'Hello, I need help with my CentrixERP free trial.'));

        return [
            'phone' => $phone,
            'phone_href' => $phoneHref,
            'whatsapp' => $whatsapp,
            'whatsapp_url' => 'https://wa.me/'.$whatsapp.'?text='.rawurlencode($message),
        ];
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

        // Mirror platform provisioning: apply the setup type’s applications so Instant trial
        // tenants get the same modules an admin would when selecting that setup.
        // Drop non-provisionable ids that may still appear on older setup documents.
        $applications = array_intersect_key(
            $this->setupProfiles->applicationsForProfile($profile),
            array_flip(ApplicationProvisioner::ids()),
        );
        $platformConfig = app(OrganizationPlatformConfigService::class);
        $salesPlatform = $platformConfig->defaultSalesPlatformConfig($profile);
        $mobileOrders = array_key_exists('enable_mobile_orders', $salesPlatform)
            ? (bool) $salesPlatform['enable_mobile_orders']
            : true;
        $enabledModules = $platformConfig->reconcileEnabledModules(
            new Organization(['deployment_profile' => $profile]),
            app(ApplicationProvisioner::class)
                ->enabledModulesFromApplications($applications, $mobileOrders),
            $salesPlatform,
        );

        $result = $this->provisioning->provision([
            'company_code' => $companyCode,
            'org_name' => $orgName,
            'org_email' => $orgEmail,
            'primary_tel' => $phone,
            'org_address' => $address,
            'deployment_profile' => $profile,
            'business_type' => $businessType,
            'registration_source' => 'self_registered',
            'enabled_modules' => $enabledModules,
            'sales_platform' => $salesPlatform,
            'payroll_platform' => app(OrganizationPlatformConfigService::class)
                ->defaultPayrollPlatformConfig($profile),
            'admin_username' => $username,
            'admin_email' => $orgEmail,
            'admin_password' => $password,
            'admin_full_name' => $adminName,
        ]);

        $trialPlan = $selectedPlan ?? $this->resolveTrialPlan($industry, $profile);
        $trialDays = $this->trialDays($trialPlan);
        $subscription = $this->licenses->createOrUpdateForOrganization($result['organization'], [
            'plan_id' => $trialPlan?->id,
            'status' => 'trialing',
            'is_trial' => true,
            'trial_days' => $trialDays,
            'seat_count' => max(1, (int) ($trialPlan?->seat_limit ?? 1)),
            'apply_plan_modules' => false,
        ]);

        $loginUrl = rtrim((string) config('erp.frontend_url'), '/').'/login?'.http_build_query([
            'company_code' => $companyCode,
            'username' => $username,
            'from' => 'free-signup',
        ]);

        return [
            'organization' => $result['organization']->fresh(),
            'manager' => $result['manager'],
            'company_code' => $companyCode,
            'username' => $username,
            'industry' => $industry,
            'business_type' => $businessType,
            'setup_type' => $profile,
            'setup_type_label' => $setupTypeOption['label'],
            'free_plan_name' => $this->freePlan->name(),
            'trial_days' => $trialDays,
            'trial_ends_at' => optional($subscription->trial_ends_at)->toIso8601String(),
            'trial_plan' => $trialPlan ? [
                'id' => (int) $trialPlan->id,
                'name' => (string) $trialPlan->name,
                'monthly_price' => $trialPlan->monthly_price !== null ? round((float) $trialPlan->monthly_price, 2) : null,
                'yearly_price' => $trialPlan->yearly_price !== null ? round((float) $trialPlan->yearly_price, 2) : null,
                'currency' => (string) ($trialPlan->currency ?: 'KES'),
                'seat_limit' => max(1, (int) ($trialPlan->seat_limit ?? 1)),
            ] : null,
            'login_url' => $loginUrl,
        ];
    }

    /**
     * Pick the cheapest active self-serve package that allows this setup type for Instant trial.
     */
    public function resolveTrialPlan(string $industry, string $setupType): ?PlatformPlan
    {
        if (! Schema::hasTable('platform_plans')) {
            return null;
        }

        $query = PlatformPlan::query()->where('is_active', true);
        if (Schema::hasColumn('platform_plans', 'self_serve')) {
            $query->where('self_serve', true);
        }
        $query->where(function ($inner) {
            $inner->where('monthly_price', '>', 0)->orWhere('yearly_price', '>', 0);
        });
        if (Schema::hasColumn('platform_plans', 'industry')) {
            $query->where(function ($inner) use ($industry) {
                $inner->where('industry', $industry);
                if ($industry === 'commerce') {
                    $inner->orWhereNull('industry')->orWhere('industry', '');
                }
            });
        }

        $candidates = $query->orderBy('monthly_price')->orderBy('id')->get();
        foreach ($candidates as $plan) {
            $trialProfiles = is_array($plan->trial_profile_keys) ? $plan->trial_profile_keys : [];
            $setupProfiles = is_array($plan->setup_profile_keys) ? $plan->setup_profile_keys : [];
            if ($trialProfiles !== [] && ! in_array($setupType, $trialProfiles, true)) {
                continue;
            }
            if ($setupProfiles !== [] && ! in_array($setupType, $setupProfiles, true)) {
                continue;
            }

            return $plan;
        }

        return $candidates->first();
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
