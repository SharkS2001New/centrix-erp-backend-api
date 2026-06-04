<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TemporaryCart extends Model
{
    use HasFactory;

    protected $table = 'temporary_carts';
    protected $fillable = [
        'user_id', 'branch_id', 'channel', 'till_id', 'route_id', 'update_no',
    ];

    public function lines()
    {
        return $this->hasMany(CartLine::class, 'cart_id');
    }
}
