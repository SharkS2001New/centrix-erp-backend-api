<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesViaParentOrganization;
use App\Models\CartLine;

class CartLineController extends BaseResourceController
{
    use ScopesViaParentOrganization;

    protected function modelClass(): string
    {
        return CartLine::class;
    }

    protected function parentOrganizationScope(): array
    {
        return ['relation' => 'cart', 'via_branch' => true];
    }
}
