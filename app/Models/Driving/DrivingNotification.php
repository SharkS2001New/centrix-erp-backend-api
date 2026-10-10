<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;

class DrivingNotification extends Model
{
    protected $fillable = [
        'organization_id', 'student_id', 'template', 'channel', 'status', 'body',
    ];
}
