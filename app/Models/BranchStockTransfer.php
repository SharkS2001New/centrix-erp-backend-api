<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BranchStockTransfer extends Model
{
    use HasFactory;

    protected $table = 'branch_stock_transfers';

    protected $fillable = [
        'organization_id',
        'from_branch_id',
        'to_branch_id',
        'product_code',
        'quantity',
        'from_location',
        'to_location',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'float',
    ];

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_code', 'product_code');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
