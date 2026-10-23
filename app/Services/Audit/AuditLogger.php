<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    /** @var array<string, true> */
    private array $automatic = [];

    /** @var array<string, true> */
    private array $explicit = [];

    public function log(
        User $user,
        string $action,
        string $tableName,
        string|int $recordId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
    ): void {
        if ($tableName === 'audit_logs' || ! $user->organization_id) {
            return;
        }

        $fingerprint = $this->fingerprint($user, $action, $tableName, $recordId);
        if (isset($this->automatic[$fingerprint])) {
            unset($this->automatic[$fingerprint]);

            return;
        }

        $this->explicit[$fingerprint] = true;
        $this->write($user, $action, $tableName, $recordId, $oldValues, $newValues, $request);
    }

    /**
     * Model events call this. A later explicit log of the same change is skipped,
     * and this is skipped when that explicit log already ran.
     */
    public function logAutomatic(
        User $user,
        string $action,
        string $tableName,
        string|int $recordId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
    ): void {
        if ($tableName === 'audit_logs' || ! $user->organization_id) {
            return;
        }

        $fingerprint = $this->fingerprint($user, $action, $tableName, $recordId);
        if (isset($this->explicit[$fingerprint])) {
            unset($this->explicit[$fingerprint]);

            return;
        }

        $this->automatic[$fingerprint] = true;
        $this->write($user, $action, $tableName, $recordId, $oldValues, $newValues, $request);
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

    /** @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public function redactValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $redacted = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|passwd|secret|token|pin|authorization|api_key|private_key|cvv|card_number/i', $key)) {
                $redacted[$key] = ($value === null || $value === '') ? $value : '[redacted]';

                continue;
            }
            if (is_array($value)) {
                $redacted[$key] = $this->redactValues($value);

                continue;
            }
            if (is_string($value) && strlen($value) > 2000) {
                $redacted[$key] = substr($value, 0, 2000).'…';

                continue;
            }
            $redacted[$key] = $value;
        }

        return $redacted;
    }

    protected function write(
        User $user,
        string $action,
        string $tableName,
        string|int $recordId,
        ?array $oldValues,
        ?array $newValues,
        ?Request $request,
    ): void {
        $attributes = [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'branch_id' => $user->branch_id,
            'action' => $action,
            'table_name' => $tableName,
            'record_id' => (string) $recordId,
            'old_values' => $this->redactValues($oldValues),
            'new_values' => $this->redactValues($newValues),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ];

        try {
            $this->persistAuditLog($attributes);
        } catch (QueryException $e) {
            if (! $this->isTransientLockConflict($e)) {
                throw $e;
            }

            $diagnostic = [
                'event' => 'audit_log_lock_conflict',
                'connection' => $e->connectionName,
                'organization_id' => $user->organization_id,
                'action' => $action,
                'table_name' => $tableName,
                'record_id' => (string) $recordId,
                'sql_state' => $e->errorInfo[0] ?? $e->getCode(),
                'driver_error_code' => $e->errorInfo[1] ?? null,
            ];

            try {
                error_log(json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (\Throwable) {
                // Audit logging is best-effort; do not route lock conflicts through alerting/log mail.
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    protected function persistAuditLog(array $attributes): void
    {
        AuditLog::create($attributes);
    }

    protected function isTransientLockConflict(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        if (in_array($driverCode, [1205, 1213], true) || $exception->getCode() === '40001') {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'lock wait timeout exceeded')
            || str_contains($message, 'deadlock found when trying to get lock');
    }

    private function fingerprint(User $user, string $action, string $tableName, string|int $recordId): string
    {
        return $user->id.'|'.$action.'|'.$tableName.'|'.$recordId;
    }
}
