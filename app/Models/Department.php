<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Department extends Model
{
    use HasFactory;

    protected $table = 'departments';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'department_code',
        'department_name',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];
}
