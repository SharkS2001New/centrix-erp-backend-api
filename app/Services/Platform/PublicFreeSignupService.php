<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\IndustryRegistry;
use App\Services\OrganizationProvisioningService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public marketing-site signup onto Package 1 (free plan). No paid subscription is created.
 */
class PublicFreeSignupService
{
    public function __construct(
        protected OrganizationProvisioningService $provisioning,
        protected FreePlanAccess $freePlan,
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
     *   industry: string,
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

        $industry = strtolower(trim((string) ($input['industry'] ?? '')));
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
