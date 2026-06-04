<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Position extends Model
{
    use HasFactory;

    protected $table = 'positions';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'position_code',
        'position_title',
        'description',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function employees()
    {
        return $this->hasMany(Employee::class, 'position_id');
    }
}
