<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\UserLoginService;
use App\Services\Cache\CapabilitiesCacheInvalidator;
use Illuminate\Support\Facades\DB;

class OrganizationDeprovisioningService
{
    public function delete(Organization $org): void
    {
        DB::transaction(function () use ($org) {
            User::query()
                ->where('organization_id', $org->id)
                ->each(fn (User $user) => app(UserLoginService::class)->disableLogin($user));

            $org->is_active = false;
            $org->company_code = $this->retireCompanyCode($org);
            $org->save();
            $org->delete();
        });

        CapabilitiesCacheInvalidator::forOrganization((int) $org->id);
    }

    protected function retireCompanyCode(Organization $org): string
    {
        $suffix = '__deleted__'.$org->id;
        $base = preg_replace('/__deleted__\d+$/', '', (string) $org->company_code) ?: (string) $org->company_code;
        $retired = $base.$suffix;

        if (strlen($retired) > 45) {
            $retired = substr($base, 0, max(1, 45 - strlen($suffix))).$suffix;
        }

        return strtoupper($retired);
    }
}
