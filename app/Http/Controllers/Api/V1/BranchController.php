<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Branch;

class BranchController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Branch::class;
    }
}
