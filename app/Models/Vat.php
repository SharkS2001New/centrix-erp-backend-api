<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vat extends Model
{
    use HasFactory;

    protected $table = 'vats';
    public $timestamps = false;
    protected $fillable = ['vat_code', 'vat_name', 'vat_percentage', 'is_active', 'created_by'];
    protected $casts = ['is_active' => 'boolean', 'vat_percentage' => 'decimal:2'];
}
