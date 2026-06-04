<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class KraResponse extends Model
{
    use HasFactory;
    protected $table = 'kra_responses';
    protected $fillable = [
        'sale_id',
        'order_no',
        'invoice_number',
        'receipt_signature',
        'signature_link',
        'serial_number',
        'kra_timestamp',
        'request_payload',
        'response_payload',
        'status',
        'error_message',
    ];
    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];
}
