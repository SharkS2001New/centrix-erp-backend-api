<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CurrentStock extends Model
{
    use HasFactory;

    protected $table = 'current_stock';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['product_code', 'branch_id', 'shop_quantity', 'store_quantity'];

    #[\Override]
    protected function setKeysForSaveQuery($query)
    {
        $query->where('product_code', $this->getAttribute('product_code'))
            ->where('branch_id', $this->getAttribute('branch_id'));

        return $query;
    }
}
