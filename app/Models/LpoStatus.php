<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LpoStatus extends Model
{
    use HasFactory;

    protected $table = 'lpo_statuses';

    protected $primaryKey = 'status_code';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'status_code',
        'status_name',
    ];

    protected $casts = [
        'status_code' => 'integer',
    ];
}
