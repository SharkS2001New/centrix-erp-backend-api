<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Expense;

class ExpenseController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Expense::class;
    }
}
