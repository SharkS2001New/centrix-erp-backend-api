<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Permission;

class PermissionController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Permission::class;
    }
}
