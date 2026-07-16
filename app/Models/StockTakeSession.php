<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTakeSession extends Model
{
    use HasFactory;

    protected $table = 'stock_take_sessions';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'session_code',
        'status',
        'stock_location',
        'filter_category_id',
        'filter_subcategory_id',
        'filter_supplier_id',
        'started_by',
        'completed_by',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'filter_category_id' => 'integer',
        'filter_subcategory_id' => 'integer',
        'filter_supplier_id' => 'integer',
    ];
}
