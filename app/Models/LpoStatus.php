<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LpoStatus extends Model
{
    use HasFactory;
    protected $table = 'lpo_statuses';
    protected $primaryKey = 'status_code';
    public $timestamps = false;
    protected $fillable = [
        'status_code',
        'status_name',
    ];
}
