<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesViaParentOrganization;
use App\Models\SalePayment;

class SalePaymentController extends BaseResourceController
{
    use ScopesViaParentOrganization;

    protected function modelClass(): string
    {
        return SalePayment::class;
    }

    protected function parentOrganizationScope(): array
    {
        return ['relation' => 'sale'];
    }
}
