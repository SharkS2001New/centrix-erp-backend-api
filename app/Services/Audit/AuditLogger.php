<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function log(
        User $user,
        string $action,
        string $tableName,
        string|int $recordId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
    ): void {
        if ($tableName === 'audit_logs') {
            return;
        }

        AuditLog::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'branch_id' => $user->branch_id,
            'action' => $action,
            'table_name' => $tableName,
            'record_id' => (string) $recordId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    public function logModel(
        User $user,
        string $action,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
    ): void {
        $key = $model->getKey();
        if ($key === null) {
            return;
        }

        $this->log(
            $user,
            $action,
            $model->getTable(),
            $key,
            $oldValues,
            $newValues ?? ($action !== 'delete' ? $model->getAttributes() : null),
            $request,
        );
    }
}
