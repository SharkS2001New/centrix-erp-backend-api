<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Erp\ModuleRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $platformCode = config('erp.platform_company_code', 'PLATFORM');
        $email = config('erp.platform_super_admin_email');
        if (! is_string($email) || trim($email) === '') {
            $this->command?->warn('Skipped platform super admin — set PLATFORM_SUPER_ADMIN_EMAIL in .env.');

            return;
        }
        $email = trim($email);
        $password = config('erp.platform_super_admin_password');
        if (! is_string($password) || $password === '') {
            $this->command?->warn('Skipped platform super admin — set PLATFORM_SUPER_ADMIN_PASSWORD in .env.');

            return;
        }

        $platformModules = array_fill_keys(ModuleRegistry::keys(), false);

        $org = Organization::query()->firstOrCreate(
            ['company_code' => $platformCode],
            [
                'org_name' => 'Platform Administration',
                'org_email' => $email,
                'primary_tel' => '0700000000',
                'org_address' => 'Platform',
                'deployment_profile' => 'small_shop',
                'enabled_modules' => $platformModules,
                'module_settings' => ['platform' => true],
            ],
        );

        $org->forceFill([
            'org_email' => $email,
            'enabled_modules' => $platformModules,
        ])->save();

        $branch = Branch::query()->firstOrCreate(
            ['organization_id' => $org->id, 'branch_code' => 'HQ'],
            [
                'branch_name' => 'Platform HQ',
                'branch_type' => 'supermarket',
                'branch_phone' => '0700000000',
            ],
        );

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Platform Operator', 'scope' => 'org'],
            ['is_active' => true],
        );

        User::query()->updateOrCreate(
            ['organization_id' => $org->id, 'email' => $email],
            [
                'branch_id' => $branch->id,
                'role_id' => $role->id,
                'username' => 'superadmin',
                'password' => Hash::make($password),
                'full_name' => 'Platform Super Admin',
                'is_admin' => 0,
                'is_super_admin' => 1,
                'access_scope' => 'org',
                'login_channels' => ['backoffice'],
                'is_active' => true,
            ],
        );

        $this->command?->info("Platform super admin ready: {$email}");
    }
}
