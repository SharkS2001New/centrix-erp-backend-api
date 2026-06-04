<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeDocument extends Model
{
    use HasFactory;

    protected $table = 'employee_documents';
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'document_type',
        'title',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'uploaded_by',
        'notes',
    ];

    protected $appends = ['file_url'];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function fileUrl(): Attribute
    {
        return Attribute::get(function () {
            $base = rtrim((string) config('app.url'), '/');

            return $base.'/api/v1/employees/'.$this->employee_id.'/documents/'.$this->id.'/file';
        });
    }
}
