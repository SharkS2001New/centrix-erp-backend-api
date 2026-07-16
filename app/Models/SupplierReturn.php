<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SupplierReturn extends Model
{
    use HasFactory;

    protected $table = 'supplier_returns';
    const UPDATED_AT = null;
    protected $fillable = [
        'organization_id', 'supplier_id', 'branch_id', 'product_code', 'quantity', 'package_type',
        'uom_label', 'stock_location', 'reason', 'reference_type', 'reference_id',
        'returned_by',
    ];
}
