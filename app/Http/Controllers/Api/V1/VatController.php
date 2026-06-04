<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Vat;

class VatController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Vat::class;
    }
}
