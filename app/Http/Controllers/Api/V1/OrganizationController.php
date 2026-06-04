<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;

class OrganizationController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Organization::class;
    }
}
