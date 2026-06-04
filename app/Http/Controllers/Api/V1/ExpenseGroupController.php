<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\ExpenseGroup;

class ExpenseGroupController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return ExpenseGroup::class;
    }
}
