<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AuditLog extends Model
{
    use HasFactory;
    protected $table = 'audit_logs';
    public $timestamps = false;
    protected $fillable = [
        'user_id',
        'branch_id',
        'action',
        'table_name',
        'record_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];
}
