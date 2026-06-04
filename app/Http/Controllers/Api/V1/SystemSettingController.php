<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\SystemSetting;

class SystemSettingController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return SystemSetting::class;
    }
}
