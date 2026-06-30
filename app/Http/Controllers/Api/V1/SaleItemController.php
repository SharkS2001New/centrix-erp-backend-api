<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesViaParentOrganization;
use App\Models\SaleItem;

class SaleItemController extends BaseResourceController
{
    use ScopesViaParentOrganization;

    protected function modelClass(): string
    {
        return SaleItem::class;
    }

    protected function parentOrganizationScope(): array
    {
        return ['relation' => 'sale'];
    }
}
