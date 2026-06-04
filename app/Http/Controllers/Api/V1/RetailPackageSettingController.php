<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\RetailPackageSetting;

class RetailPackageSettingController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return RetailPackageSetting::class;
    }
}
