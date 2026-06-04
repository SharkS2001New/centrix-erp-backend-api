<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Department;

class DepartmentController extends BaseResourceController
{
    protected function modelClass(): string { return Department::class; }
}
