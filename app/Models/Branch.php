<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Branch extends Model
{
    use HasFactory;

    protected $table = 'branches';
    protected $fillable = [
        'organization_id', 'branch_code', 'branch_name', 'branch_address',
        'branch_phone', 'branch_email', 'branch_type', 'is_active', 'settings',
    ];
    protected $casts = ['settings' => 'array', 'is_active' => 'boolean'];
}
