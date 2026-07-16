<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationProduct;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Damage extends Model
{
    use BelongsToOrganizationProduct;
    use HasFactory;

    protected $table = 'damages';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'product_code',
        'branch_id',
        'quantity',
        'package_type',
        'uom_label',
        'stock_location',
        'reason',
        'reported_by',
    ];

    const CREATED_AT = 'created_at';
}
