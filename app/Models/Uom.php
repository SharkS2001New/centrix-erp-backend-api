<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Uom extends Model
{
    use HasFactory;

    protected $table = 'uoms';
    public $timestamps = false;
    protected $fillable = [
        'conversion_factor', 'full_name', 'uom_type', 'is_base_unit', 'is_active',
        'created_by', 'deleted_by', 'deleted_at',
    ];
    protected $casts = ['is_active' => 'boolean', 'deleted_at' => 'datetime'];
}
