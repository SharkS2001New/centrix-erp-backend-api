<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Role;

class RoleController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Role::class;
    }
}
