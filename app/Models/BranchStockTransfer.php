<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationProduct;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BranchStockTransfer extends Model
{
    use BelongsToOrganizationProduct;
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
