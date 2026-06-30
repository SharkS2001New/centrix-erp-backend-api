<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesViaParentOrganization;
use App\Models\LpoSupplierInvoice;

class LpoSupplierInvoiceController extends BaseResourceController
{
    use ScopesViaParentOrganization;

    protected function modelClass(): string
    {
        return LpoSupplierInvoice::class;
    }

    protected function parentOrganizationScope(): array
    {
        return ['relation' => 'lpo'];
    }
}
