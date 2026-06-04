<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Role extends Model
{
    use HasFactory;
    protected $table = 'roles';
    public $timestamps = false;
    protected $fillable = [
        'role_name',
        'scope',
        'is_active',
    ];
    protected $casts = [
        'is_active' => 'boolean',
    ];
}
