<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingAccountMapping extends Model
{
    protected $fillable = [
        'organization_id',
        'provider',
        'local_account_code',
        'external_account_id',
        'external_account_name',
    ];
}
