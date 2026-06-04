<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;

class AuditLogController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return AuditLog::class;
    }
}
