<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CurrentStock extends Model
{
    use HasFactory;

    protected $table = 'current_stock';

    /** Composite key (product_code, branch_id) — no single `id` column. */
    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['product_code', 'branch_id', 'shop_quantity', 'store_quantity'];
}
