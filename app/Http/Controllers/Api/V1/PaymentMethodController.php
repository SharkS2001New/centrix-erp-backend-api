<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PaymentMethod;

class PaymentMethodController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return PaymentMethod::class;
    }

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return ['method_name', 'method_code'];
    }

    protected function defaultListOrderColumn(): ?string
    {
        return 'method_name';
    }

    protected function defaultListOrderDirection(): string
    {
        return 'asc';
    }
}
