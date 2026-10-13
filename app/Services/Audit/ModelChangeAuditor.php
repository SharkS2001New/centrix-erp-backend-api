<?php

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Writes create, update, and delete rows for the signed-in user. */
class ModelChangeAuditor
{
    /** @var list<string> */
    private const SKIP_TABLES = [
        'audit_logs',
        'personal_access_tokens',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
        'notifications',
        'password_reset_tokens',
        'migrations',
    ];

    /** @var list<string> */
    private const NOISE_COLUMNS = [
        'updated_at',
        'created_at',
        'last_login',
        'last_login_at',
        'last_seen_at',
        'remember_token',
        'deleted_at',
    ];

    public function __construct(private AuditLogger $audit) {}

    public function created(Model $model): void
    {
        $this->record('create', $model, null, $this->snapshot($model));
    }

    public function updated(Model $model): void
    {
        $changes = $this->withoutNoise($model->getChanges());
        if ($changes === []) {
            return;
        }

        $previous = [];
        foreach (array_keys($changes) as $column) {
            $previous[$column] = $model->getOriginal($column);
        }

        $this->record('update', $model, $previous, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->record('delete', $model, $this->snapshot($model), null);
    }

    /** @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function record(string $action, Model $model, ?array $oldValues, ?array $newValues): void
    {
        $table = $model->getTable();
        if ($this->skipped($table)) {
            return;
        }

        $key = $model->getKey();
        if ($key === null) {
            return;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $request = app()->bound('request') ? request() : null;

        $this->audit->logAutomatic(
            $user,
            $action,
            $table,
            $key,
            $oldValues,
            $newValues,
            $request,
        );
    }

    private function skipped(string $table): bool
    {
        return in_array($table, self::SKIP_TABLES, true)
            || str_starts_with($table, 'telescope_')
            || str_starts_with($table, 'pulse_');
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $model): array
    {
        return $this->withoutNoise($model->getAttributes());
    }

    /** @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutNoise(array $values): array
    {
        foreach (self::NOISE_COLUMNS as $column) {
            unset($values[$column]);
        }

        return $values;
    }
}
