<?php

namespace App\Services\Retention;

use App\Models\AuditLog;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeClockSession;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionAgentCommand;
use App\Models\KraAgentCommand;
use App\Models\Sale;
use App\Models\StockReservation;
use App\Services\Sales\SaleHardDeleteService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class OperationalDataPruneService
{
    /**
     * User-facing prune targets (table aliases) → what gets deleted.
     *
     * @var list<string>
     */
    public const TARGETS = [
        'hikvision_agent_commands',
        'hikvision_access_events',
        'employee_attendance',
        'kra_agent_commands',
        'stock_reservations',
        'audit_logs',
        'cancelled_sales',
        'expired_sales',
    ];

    public function __construct(protected SaleHardDeleteService $saleHardDelete) {}

    /**
     * @return array<string, int>
     */
    public function pruneAll(bool $dryRun = false, ?int $days = null): array
    {
        return $this->pruneTargets(null, $days, $dryRun);
    }

    /**
     * Delete retention data for selected tables. When $days is set, rows older than
     * that many days are removed (one-off override; does not change saved timers).
     *
     * @param  list<string>|null  $targets  Null/empty = all targets. Use table aliases from TARGETS.
     * @param  (callable(array{event: string, key?: string, message: string, phase?: string, count?: int, total?: int}): void)|null  $onProgress
     * @return array<string, int>
     */
    public function pruneTargets(
        ?array $targets = null,
        ?int $days = null,
        bool $dryRun = false,
        ?callable $onProgress = null,
    ): array {
        DataRetentionSettingsResolver::applyToRuntime();

        $selected = $this->normalizeTargets($targets);
        $overrideDays = $days !== null ? max(1, min(365, $days)) : null;
        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $scope = $overrideDays !== null
            ? "older than {$overrideDays} day(s)"
            : 'using saved retention timers';

        $this->emitProgress($onProgress, [
            'event' => 'status',
            'message' => ($dryRun ? 'Dry run' : 'Prune')." starting ({$scope})…",
            'phase' => 'start',
        ]);

        $results = [];

        if (in_array('stock_reservations', $selected, true)) {
            $daysUsed = $overrideDays ?? $this->releasedReservationDays();
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'released_stock_reservations',
                'message' => "Scanning released stock_reservations older than {$daysUsed} day(s)…",
                'phase' => 'start',
            ]);
            $count = $this->pruneReleasedStockReservations($dryRun, $daysUsed);
            $results['released_stock_reservations'] = $count;
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'released_stock_reservations',
                'message' => "{$verb} {$count} released_stock_reservations",
                'phase' => 'done',
                'count' => $count,
            ]);
        }

        if (in_array('kra_agent_commands', $selected, true)) {
            $completed = $overrideDays ?? $this->completedKraDays();
            $failed = $overrideDays ?? $this->failedKraDays();
            foreach ([
                ['completed', $completed],
                ['failed', $failed],
                ['expired', $failed],
            ] as [$status, $daysUsed]) {
                $key = "kra_agent_commands_{$status}";
                $this->emitProgress($onProgress, [
                    'event' => 'step',
                    'key' => $key,
                    'message' => "Scanning kra_agent_commands ({$status}) older than {$daysUsed} day(s)…",
                    'phase' => 'start',
                ]);
                $count = $this->pruneKraAgentCommands($status, $daysUsed, $dryRun);
                $results[$key] = $count;
                $this->emitProgress($onProgress, [
                    'event' => 'step',
                    'key' => $key,
                    'message' => "{$verb} {$count} {$key}",
                    'phase' => 'done',
                    'count' => $count,
                ]);
            }
        }

        if (in_array('hikvision_agent_commands', $selected, true)) {
            $completed = $overrideDays ?? $this->completedHikvisionDays();
            $failed = $overrideDays ?? $this->failedHikvisionDays();
            foreach ([
                ['completed', $completed],
                ['failed', $failed],
                ['expired', $failed],
            ] as [$status, $daysUsed]) {
                $key = "hikvision_agent_commands_{$status}";
                $this->emitProgress($onProgress, [
                    'event' => 'step',
                    'key' => $key,
                    'message' => "Scanning hikvision_agent_commands ({$status}) older than {$daysUsed} day(s)…",
                    'phase' => 'start',
                ]);
                $count = $this->pruneHikvisionAgentCommands($status, $daysUsed, $dryRun);
                $results[$key] = $count;
                $this->emitProgress($onProgress, [
                    'event' => 'step',
                    'key' => $key,
                    'message' => "{$verb} {$count} {$key}",
                    'phase' => 'done',
                    'count' => $count,
                ]);
            }
        }

        if (in_array('hikvision_access_events', $selected, true)) {
            $eventDays = $overrideDays ?? $this->hikvisionAccessEventsDays();
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'hikvision_access_events',
                'message' => "Scanning hikvision_access_events older than {$eventDays} day(s)…",
                'phase' => 'start',
            ]);
            $count = $this->pruneHikvisionAccessEvents($dryRun, $eventDays);
            $results['hikvision_access_events'] = $count;
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'hikvision_access_events',
                'message' => "{$verb} {$count} hikvision_access_events",
                'phase' => 'done',
                'count' => $count,
            ]);

            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'forgotten_clock_out_alerts',
                'message' => "Clearing forgotten clock-out alerts older than {$eventDays} day(s)…",
                'phase' => 'start',
            ]);
            $alertCount = $this->pruneForgottenClockOutAlerts($dryRun, $eventDays);
            $results['forgotten_clock_out_alerts'] = $alertCount;
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'forgotten_clock_out_alerts',
                'message' => ($dryRun ? 'Would clear' : 'Cleared')." {$alertCount} forgotten_clock_out_alerts",
                'phase' => 'done',
                'count' => $alertCount,
            ]);
        }

        if (in_array('employee_attendance', $selected, true)) {
            $attendanceDays = max(7, $overrideDays ?? $this->attendanceDays());
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'employee_clock_sessions',
                'message' => "Scanning employee_clock_sessions older than {$attendanceDays} day(s)…",
                'phase' => 'start',
            ]);
            $sessionCount = $this->pruneOldEmployeeClockSessions($dryRun, $attendanceDays);
            $results['employee_clock_sessions'] = $sessionCount;
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'employee_clock_sessions',
                'message' => "{$verb} {$sessionCount} employee_clock_sessions",
                'phase' => 'done',
                'count' => $sessionCount,
            ]);

            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'employee_attendance',
                'message' => "Scanning employee_attendance older than {$attendanceDays} day(s)…",
                'phase' => 'start',
            ]);
            $attendanceCount = $this->pruneOldEmployeeAttendance($dryRun, $attendanceDays);
            $results['employee_attendance'] = $attendanceCount;
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'employee_attendance',
                'message' => "{$verb} {$attendanceCount} employee_attendance",
                'phase' => 'done',
                'count' => $attendanceCount,
            ]);
        }

        if (in_array('audit_logs', $selected, true)) {
            $daysUsed = $overrideDays ?? $this->auditLogsDays();
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'audit_logs',
                'message' => "Scanning audit_logs older than {$daysUsed} day(s)…",
                'phase' => 'start',
            ]);
            $count = $this->pruneAuditLogs($dryRun, $daysUsed);
            $results['audit_logs'] = $count;
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'audit_logs',
                'message' => "{$verb} {$count} audit_logs",
                'phase' => 'done',
                'count' => $count,
            ]);
        }

        if (in_array('cancelled_sales', $selected, true)) {
            $daysUsed = $overrideDays ?? $this->cancelledSalesDays();
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'cancelled_sales',
                'message' => "Hard-deleting cancelled sales older than {$daysUsed} day(s)…",
                'phase' => 'start',
            ]);
            $count = $this->pruneTerminalSales('cancelled', 'cancelled_at', $daysUsed, $dryRun);
            $results['cancelled_sales'] = $count;
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'cancelled_sales',
                'message' => "{$verb} {$count} cancelled_sales",
                'phase' => 'done',
                'count' => $count,
            ]);
        }

        if (in_array('expired_sales', $selected, true)) {
            $daysUsed = $overrideDays ?? $this->expiredSalesDays();
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'expired_sales',
                'message' => "Hard-deleting expired sales older than {$daysUsed} day(s)…",
                'phase' => 'start',
            ]);
            $count = $this->pruneTerminalSales('expired', 'expired_at', $daysUsed, $dryRun);
            $results['expired_sales'] = $count;
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'expired_sales',
                'message' => "{$verb} {$count} expired_sales",
                'phase' => 'done',
                'count' => $count,
            ]);
        }

        $total = array_sum($results);
        $this->emitProgress($onProgress, [
            'event' => 'status',
            'message' => $dryRun
                ? "Dry run complete — would delete {$total} rows total."
                : "Prune complete — deleted {$total} rows total.",
            'phase' => 'done',
            'total' => $total,
        ]);

        return $results;
    }

    /**
     * @param  (callable(array<string, mixed>): void)|null  $onProgress
     * @param  array<string, mixed>  $payload
     */
    protected function emitProgress(?callable $onProgress, array $payload): void
    {
        if ($onProgress === null) {
            return;
        }

        $onProgress($payload);
    }

    /**
     * @param  list<string>|null  $targets
     * @return list<string>
     */
    public function normalizeTargets(?array $targets): array
    {
        if ($targets === null || $targets === []) {
            return self::TARGETS;
        }

        $aliases = [
            'released_stock_reservations' => 'stock_reservations',
            'attendance' => 'employee_attendance',
            'employee_clock_sessions' => 'employee_attendance',
            'forgotten_clock_out_alerts' => 'hikvision_access_events',
        ];

        $normalized = [];
        foreach ($targets as $raw) {
            $key = strtolower(trim((string) $raw));
            if ($key === '' || $key === 'all') {
                return self::TARGETS;
            }
            $key = $aliases[$key] ?? $key;
            if (! in_array($key, self::TARGETS, true)) {
                throw new InvalidArgumentException(
                    'Unknown prune target "'.$raw.'". Allowed: '.implode(', ', self::TARGETS)
                );
            }
            $normalized[] = $key;
        }

        return array_values(array_unique($normalized));
    }

    public function pruneReleasedStockReservations(bool $dryRun = false, ?int $days = null): int
    {
        if (! Schema::hasTable('stock_reservations')) {
            return 0;
        }

        $days = max(1, $days ?? $this->releasedReservationDays());
        $cutoff = Carbon::now()->subDays($days);
        $query = StockReservation::query()
            ->whereNotNull('released_at')
            ->where('released_at', '<', $cutoff);

        $count = (clone $query)->count();
        if (! $dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    public function pruneKraAgentCommands(string $status, int $days, bool $dryRun = false): int
    {
        if (! Schema::hasTable('kra_agent_commands')) {
            return 0;
        }

        $days = max(1, $days);
        $cutoff = Carbon::now()->subDays($days);

        $query = KraAgentCommand::query()->where('status', $status);
        if (Schema::hasColumn('kra_agent_commands', 'completed_at')) {
            $query->where(function ($q) use ($cutoff) {
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNotNull('completed_at')->where('completed_at', '<', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('completed_at')->where('created_at', '<', $cutoff);
                });
            });
        } else {
            $query->where('created_at', '<', $cutoff);
        }

        $count = (clone $query)->count();
        if (! $dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    public function pruneHikvisionAgentCommands(string $status, int $days, bool $dryRun = false): int
    {
        if (! Schema::hasTable('hikvision_agent_commands')) {
            return 0;
        }

        $days = max(1, $days);
        $cutoff = Carbon::now()->subDays($days);

        $query = HikvisionAgentCommand::query()->where('status', $status);
        if (Schema::hasColumn('hikvision_agent_commands', 'completed_at')) {
            $query->where(function ($q) use ($cutoff) {
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNotNull('completed_at')->where('completed_at', '<', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('completed_at')->where('created_at', '<', $cutoff);
                });
            });
        } else {
            $query->where('created_at', '<', $cutoff);
        }

        $count = (clone $query)->count();
        if (! $dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    /**
     * All terminal punch logs — applied, missed (outside window), duplicates, etc.
     */
    public function pruneHikvisionAccessEvents(bool $dryRun = false, ?int $days = null): int
    {
        if (! Schema::hasTable('hikvision_access_events')) {
            return 0;
        }

        $days = max(1, $days ?? $this->hikvisionAccessEventsDays());
        $cutoff = Carbon::now()->subDays($days);
        $query = HikvisionAccessEvent::query()->where('event_time', '<', $cutoff);

        return $this->deleteInBatches($query, $dryRun);
    }

    /**
     * Drop “forgotten clock-out” from Missed Punches after retention (keep the session for attendance).
     */
    public function pruneForgottenClockOutAlerts(bool $dryRun = false, ?int $days = null): int
    {
        if (! Schema::hasTable('employee_clock_sessions')
            || ! Schema::hasColumn('employee_clock_sessions', 'needs_reconciliation')) {
            return 0;
        }

        $days = max(1, $days ?? $this->hikvisionAccessEventsDays());
        $cutoff = Carbon::now()->subDays($days);

        $query = EmployeeClockSession::query()
            ->where('needs_reconciliation', true)
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNotNull('clock_out_at')->where('clock_out_at', '<', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('clock_out_at')->where('clock_in_at', '<', $cutoff);
                });
            });

        $count = (clone $query)->count();
        if (! $dryRun && $count > 0) {
            $query->limit(5000)->update(['needs_reconciliation' => false]);
        }

        return min($count, 5000);
    }

    public function pruneOldEmployeeClockSessions(bool $dryRun = false, ?int $days = null): int
    {
        if (! Schema::hasTable('employee_clock_sessions')) {
            return 0;
        }

        $cutoff = $this->attendanceCutoffDate($days)->startOfDay();
        $base = EmployeeClockSession::query()->where('clock_in_at', '<', $cutoff);
        if ($dryRun) {
            return min((clone $base)->count(), 25_000);
        }

        $deleted = 0;
        for ($i = 0; $i < 5; $i++) {
            $ids = (clone $base)->orderBy('id')->limit(5000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            if (Schema::hasTable('hikvision_access_events')
                && Schema::hasColumn('hikvision_access_events', 'clock_session_id')) {
                HikvisionAccessEvent::query()
                    ->whereIn('clock_session_id', $ids)
                    ->update(['clock_session_id' => null]);
            }

            EmployeeClockSession::query()->whereIn('id', $ids)->delete();
            $deleted += $ids->count();
        }

        return $deleted;
    }

    public function pruneOldEmployeeAttendance(bool $dryRun = false, ?int $days = null): int
    {
        if (! Schema::hasTable('employee_attendance')) {
            return 0;
        }

        $cutoff = $this->attendanceCutoffDate($days)->toDateString();
        $base = EmployeeAttendance::query()->where('attendance_date', '<', $cutoff);
        if ($dryRun) {
            return min((clone $base)->count(), 25_000);
        }

        $deleted = 0;
        for ($i = 0; $i < 5; $i++) {
            $ids = (clone $base)->orderBy('id')->limit(5000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            if (Schema::hasTable('employee_clock_sessions')) {
                EmployeeClockSession::query()
                    ->whereIn('attendance_id', $ids)
                    ->update(['attendance_id' => null]);
            }

            EmployeeAttendance::query()->whereIn('id', $ids)->delete();
            $deleted += $ids->count();
        }

        return $deleted;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    protected function deleteInBatches($query, bool $dryRun, int $batchSize = 5000, int $maxBatches = 5): int
    {
        if ($dryRun) {
            return min((clone $query)->count(), $batchSize * $maxBatches);
        }

        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $ids = (clone $query)->orderBy('id')->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $query->getModel()->newQuery()->whereIn('id', $ids)->delete();
            $deleted += $ids->count();
        }

        return $deleted;
    }

    public function pruneAuditLogs(bool $dryRun = false, ?int $days = null): int
    {
        if (! Schema::hasTable('audit_logs')) {
            return 0;
        }

        $days = max(1, $days ?? $this->auditLogsDays());
        $cutoff = Carbon::now()->subDays($days)->startOfDay();
        $query = AuditLog::query()->where('created_at', '<', $cutoff);
        $count = (clone $query)->count();
        if (! $dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    public function pruneTerminalSales(string $status, string $dateColumn, int $days, bool $dryRun = false): int
    {
        if (! Schema::hasTable('sales') || ! Schema::hasColumn('sales', $dateColumn)) {
            return 0;
        }

        $days = max(1, $days);
        $cutoff = Carbon::now()->subDays($days);
        $ids = Sale::query()
            ->where('status', $status)
            ->whereNotNull($dateColumn)
            ->where($dateColumn, '<', $cutoff)
            ->orderBy('id')
            ->limit(500)
            ->pluck('id')
            ->all();

        if ($dryRun) {
            return count($ids);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $sale = Sale::query()->find($id);
            if (! $sale) {
                continue;
            }
            try {
                $this->saleHardDelete->delete($sale);
                $deleted++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $deleted;
    }

    /**
     * @return array{
     *   retention: array<string, int|string>,
     *   schedule_time: string,
     *   tables: list<array{name: string, mb: float, rows: int}>,
     *   prune_targets: list<string>
     * }
     */
    public function platformStatus(): array
    {
        DataRetentionSettingsResolver::applyToRuntime();

        $tables = [
            'hikvision_agent_commands',
            'hikvision_access_events',
            'employee_attendance',
            'employee_clock_sessions',
            'kra_agent_commands',
            'stock_reservations',
            'audit_logs',
        ];

        $sizes = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $row = \Illuminate\Support\Facades\DB::selectOne(
                'SELECT ROUND((data_length + index_length) / 1024 / 1024, 1) AS mb,
                        table_rows AS approx_rows
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
            );
            $sizes[] = [
                'name' => $table,
                'mb' => (float) ($row->mb ?? 0),
                'rows' => (int) ($row->approx_rows ?? 0),
            ];
        }

        usort($sizes, fn ($a, $b) => $b['mb'] <=> $a['mb']);

        $retention = DataRetentionSettingsResolver::resolve();

        return [
            'retention' => $retention,
            'schedule_time' => (string) ($retention['prune_time'] ?? config('data_retention.prune_time', '03:40')),
            'tables' => $sizes,
            'optimizable_tables' => array_values(array_map(fn ($t) => $t['name'], $sizes)),
            'prune_targets' => self::TARGETS,
        ];
    }

    /**
     * Reclaim disk after large deletes (optional; can lock tables briefly).
     *
     * @param  list<string>|null  $onlyTables  When set, only these tables are optimized.
     * @param  (callable(array<string, mixed>): void)|null  $onProgress
     * @return list<string>
     */
    public function optimizeRetentionTables(?array $onlyTables = null, ?callable $onProgress = null): array
    {
        $allowed = [
            'hikvision_agent_commands',
            'hikvision_access_events',
            'employee_attendance',
            'employee_clock_sessions',
            'kra_agent_commands',
            'stock_reservations',
            'audit_logs',
        ];

        $targets = $onlyTables !== null && $onlyTables !== []
            ? array_values(array_intersect($allowed, $onlyTables))
            : $allowed;

        $optimized = [];
        foreach ($targets as $table) {
            if (! Schema::hasTable($table)) {
                $this->emitProgress($onProgress, [
                    'event' => 'step',
                    'key' => 'optimize_'.$table,
                    'message' => "Skip OPTIMIZE {$table} (table missing)",
                    'phase' => 'done',
                ]);
                continue;
            }
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'optimize_'.$table,
                'message' => "OPTIMIZE TABLE `{$table}`…",
                'phase' => 'start',
            ]);
            \Illuminate\Support\Facades\DB::statement('OPTIMIZE TABLE `'.$table.'`');
            $optimized[] = $table;
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'optimize_'.$table,
                'message' => "Optimized {$table}",
                'phase' => 'done',
            ]);
        }

        return $optimized;
    }

    protected function attendanceCutoffDate(?int $days = null): Carbon
    {
        $days = max(7, $days ?? $this->attendanceDays());

        return Carbon::now()->subDays($days);
    }

    protected function attendanceDays(): int
    {
        return max(30, (int) config('data_retention.attendance_days', 60));
    }

    protected function hikvisionAccessEventsDays(): int
    {
        return max(1, (int) config('data_retention.hikvision_access_events_days', 7));
    }

    protected function releasedReservationDays(): int
    {
        return max(1, (int) config('data_retention.released_stock_reservations_days', 14));
    }

    protected function auditLogsDays(): int
    {
        return max(1, (int) config('data_retention.audit_logs_days', 10));
    }

    protected function completedKraDays(): int
    {
        return max(1, (int) config('data_retention.kra_agent_commands_completed_days', 1));
    }

    protected function failedKraDays(): int
    {
        return max(1, (int) config('data_retention.kra_agent_commands_failed_days', 2));
    }

    protected function completedHikvisionDays(): int
    {
        return max(1, (int) config('data_retention.hikvision_agent_commands_completed_days', 1));
    }

    protected function failedHikvisionDays(): int
    {
        return max(1, (int) config('data_retention.hikvision_agent_commands_failed_days', 2));
    }

    protected function cancelledSalesDays(): int
    {
        return max(1, (int) config('data_retention.cancelled_sales_days', 7));
    }

    protected function expiredSalesDays(): int
    {
        return max(1, (int) config('data_retention.expired_sales_days', 14));
    }
}
