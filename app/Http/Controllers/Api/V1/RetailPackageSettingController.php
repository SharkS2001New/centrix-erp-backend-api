<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesViaParentOrganization;
use App\Models\RetailPackageSetting;

class RetailPackageSettingController extends BaseResourceController
{
    use ScopesViaParentOrganization;

    protected function modelClass(): string
    {
        return RetailPackageSetting::class;
    }

    protected function parentOrganizationScope(): array
    {
        return ['relation' => 'product'];
    }
}
