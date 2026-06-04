<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Till extends Model
{
    use HasFactory;

    protected $table = 'tills';
    protected $fillable = [
        'branch_id', 'till_number', 'ip_address', 'cashier_id',
        'working_amount', 'float_breakdown',
    ];
    protected $casts = ['float_breakdown' => 'array'];
}
