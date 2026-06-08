<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Damage;

class DamageController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Damage::class;
    }
}
