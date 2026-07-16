<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Till extends Model
{
    use HasFactory;

    protected $table = 'tills';
    protected $fillable = [
        'organization_id', 'branch_id', 'till_number', 'till_name', 'description', 'is_active',
        'ip_address', 'cashier_id',
    ];
    protected $casts = ['is_active' => 'boolean'];
}
