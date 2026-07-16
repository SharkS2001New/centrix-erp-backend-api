<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vehicle extends Model
{
    use HasFactory;

    protected $table = 'vehicles';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'vehicle_code',
        'vehicle_name',
        'plate_number',
        'max_weight_kg',
        'max_volume_m3',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_weight_kg' => 'float',
        'max_volume_m3' => 'float',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
