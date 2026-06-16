<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LpoAttachment extends Model
{
    use HasFactory;

    protected $table = 'lpo_attachments';

    public $timestamps = false;

    protected $fillable = [
        'lpo_no',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
