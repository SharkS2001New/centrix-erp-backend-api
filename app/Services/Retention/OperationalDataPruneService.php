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

    /** Remaining row budget for this prune run (null = unlimited). */
    protected ?int $maxRowsRemaining = null;

    public function __construct(protected SaleHardDeleteService $saleHardDelete) {}

    /**
     * @return array<string, int>
     */
    public function pruneAll(bool $dryRun = false, ?int $days = null, ?int $maxRows = null): array
    {
        return $this->pruneTargets(null, $days, $dryRun, null, $maxRows);
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
        ?int $maxRows = null,
    ): array {
        DataRetentionSettingsResolver::applyToRuntime();

        $selected = $this->normalizeTargets($targets);
        $overrideDays = $days !== null ? max(1, min(365, $days)) : null;
        $this->maxRowsRemaining = $maxRows !== null ? max(1, min(500_000, $maxRows)) : null;
        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $scope = $overrideDays !== null
            ? "older than {$overrideDays} day(s)"
            : 'using saved retention timers';
        if ($this->maxRowsRemaining !== null) {
            $scope .= ", max {$this->maxRowsRemaining} rows this run";
        }

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

        return $this->deleteMatching($query, $dryRun);
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

        return $this->deleteMatching($query, $dryRun);
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

        return $this->deleteMatching($query, $dryRun);
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
            $count = min((clone $base)->count(), 25_000);

            return $this->consumeRowBudget($count);
        }

        $deleted = 0;
        for ($i = 0; $i < 5; $i++) {
            if ($this->maxRowsRemaining !== null && $this->maxRowsRemaining <= 0) {
                break;
            }
            $chunk = min(5000, $this->maxRowsRemaining ?? 5000);
            $ids = (clone $base)->orderBy('id')->limit($chunk)->pluck('id');
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
            $n = $ids->count();
            $deleted += $n;
            if ($this->maxRowsRemaining !== null) {
                $this->maxRowsRemaining -= $n;
            }
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
            $count = min((clone $base)->count(), 25_000);

            return $this->consumeRowBudget($count);
        }

        $deleted = 0;
        for ($i = 0; $i < 5; $i++) {
            if ($this->maxRowsRemaining !== null && $this->maxRowsRemaining <= 0) {
                break;
            }
            $chunk = min(5000, $this->maxRowsRemaining ?? 5000);
            $ids = (clone $base)->orderBy('id')->limit($chunk)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            if (Schema::hasTable('employee_clock_sessions')) {
                EmployeeClockSession::query()
                    ->whereIn('attendance_id', $ids)
                    ->update(['attendance_id' => null]);
            }

            EmployeeAttendance::query()->whereIn('id', $ids)->delete();
            $n = $ids->count();
            $deleted += $n;
            if ($this->maxRowsRemaining !== null) {
                $this->maxRowsRemaining -= $n;
            }
        }

        return $deleted;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    protected function deleteMatching($query, bool $dryRun): int
    {
        if ($this->maxRowsRemaining !== null && $this->maxRowsRemaining <= 0) {
            return 0;
        }

        $count = (clone $query)->count();
        $toDelete = $this->consumeRowBudget($count);
        if ($toDelete <= 0) {
            return 0;
        }

        if ($dryRun) {
            return $toDelete;
        }

        if ($toDelete >= $count) {
            $query->delete();

            return $count;
        }

        $deleted = 0;
        while ($deleted < $toDelete) {
            $chunk = min(5000, $toDelete - $deleted);
            $ids = (clone $query)->orderBy('id')->limit($chunk)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $query->getModel()->newQuery()->whereIn('id', $ids)->delete();
            $deleted += $ids->count();
        }

        return $deleted;
    }

    protected function consumeRowBudget(int $available): int
    {
        if ($this->maxRowsRemaining === null) {
            return $available;
        }

        $take = min($available, $this->maxRowsRemaining);
        $this->maxRowsRemaining -= $take;

        return $take;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    protected function deleteInBatches($query, bool $dryRun, int $batchSize = 5000, int $maxBatches = 5): int
    {
        if ($this->maxRowsRemaining !== null && $this->maxRowsRemaining <= 0) {
            return 0;
        }

        $cap = $this->maxRowsRemaining ?? ($batchSize * $maxBatches);
        if ($dryRun) {
            $count = min((clone $query)->count(), $cap);

            return $this->consumeRowBudget($count);
        }

        $deleted = 0;
        $limit = min($batchSize * $maxBatches, $cap);
        while ($deleted < $limit) {
            if ($this->maxRowsRemaining !== null && $this->maxRowsRemaining <= 0) {
                break;
            }
            $chunk = min($batchSize, $limit - $deleted, $this->maxRowsRemaining ?? $batchSize);
            $ids = (clone $query)->orderBy('id')->limit($chunk)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $query->getModel()->newQuery()->whereIn('id', $ids)->delete();
            $n = $ids->count();
            $deleted += $n;
            if ($this->maxRowsRemaining !== null) {
                $this->maxRowsRemaining -= $n;
            }
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

        return $this->deleteMatching($query, $dryRun);
    }

    public function pruneTerminalSales(string $status, string $dateColumn, int $days, bool $dryRun = false): int
    {
        if (! Schema::hasTable('sales') || ! Schema::hasColumn('sales', $dateColumn)) {
            return 0;
        }

        $days = max(1, $days);
        $cutoff = Carbon::now()->subDays($days);
        $limit = min(500, $this->maxRowsRemaining ?? 500);
        if ($limit <= 0) {
            return 0;
        }

        $ids = Sale::query()
            ->where('status', $status)
            ->whereNotNull($dateColumn)
            ->where($dateColumn, '<', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $toDelete = $this->consumeRowBudget(count($ids));
        if ($dryRun) {
            return $toDelete;
        }

        $deleted = 0;
        foreach (array_slice($ids, 0, $toDelete) as $id) {
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
     *   tables: list<array{name: string, mb: float, rows: int, prunable_rows?: int|null, note?: string}>,
     *   prune_targets: list<string>,
     *   notes: list<string>
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

            $entry = [
                'name' => $table,
                'mb' => (float) ($row->mb ?? 0),
                'rows' => (int) ($row->approx_rows ?? 0),
                'prunable_rows' => null,
                'note' => null,
            ];

            if ($table === 'stock_reservations') {
                $days = $this->releasedReservationDays();
                $cutoff = Carbon::now()->subDays($days);
                $releasedEligible = (int) StockReservation::query()
                    ->whereNotNull('released_at')
                    ->where('released_at', '<', $cutoff)
                    ->count();
                $active = (int) StockReservation::query()->whereNull('released_at')->count();
                $entry['prunable_rows'] = $releasedEligible;
                $entry['note'] = "Only released holds older than {$days}d are deleted ({$releasedEligible} eligible). {$active} still active (not pruned).";
            } elseif ($table === 'hikvision_agent_commands') {
                $entry['note'] = 'Already empty after delivery delete — Optimize will not shrink further.';
            }

            $sizes[] = $entry;
        }

        usort($sizes, fn ($a, $b) => $b['mb'] <=> $a['mb']);

        $retention = DataRetentionSettingsResolver::resolve();

        return [
            'retention' => $retention,
            'schedule_time' => (string) ($retention['prune_time'] ?? config('data_retention.prune_time', '03:40')),
            'tables' => $sizes,
            'optimizable_tables' => array_values(array_map(fn ($t) => $t['name'], $sizes)),
            'prune_targets' => self::TARGETS,
            'notes' => [
                'MB / row counts from information_schema are estimates and often lag until ANALYZE/OPTIMIZE.',
                'OPTIMIZE only reclaims disk after rows were deleted. Empty or already-compact tables show little/no MB change.',
                'stock_reservations prune never deletes active (unreleased) cart/sale holds.',
                'Watch Live prune log for exact “Deleted N …” counts — that proves the run, not the MB column alone.',
            ],
        ];
    }

    /**
     * Measure current table size in MB (data + indexes).
     */
    public function tableSizeMb(string $table): float
    {
        if (! Schema::hasTable($table)) {
            return 0.0;
        }

        $row = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT ROUND((data_length + index_length) / 1024 / 1024, 1) AS mb
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        );

        return (float) ($row->mb ?? 0);
    }

    /**
     * Reclaim disk after large deletes (optional; can lock tables briefly).
     *
     * @param  list<string>|null  $onlyTables  When set, only these tables are optimized.
     * @param  (callable(array<string, mixed>): void)|null  $onProgress
     * @return list<array{name: string, before_mb: float, after_mb: float, delta_mb: float}>
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
            $before = $this->tableSizeMb($table);
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'optimize_'.$table,
                'message' => "OPTIMIZE TABLE `{$table}` (before {$before} MB)…",
                'phase' => 'start',
            ]);
            \Illuminate\Support\Facades\DB::statement('OPTIMIZE TABLE `'.$table.'`');
            try {
                \Illuminate\Support\Facades\DB::statement('ANALYZE TABLE `'.$table.'`');
            } catch (\Throwable) {
                // ANALYZE is best-effort for fresher information_schema stats.
            }
            $after = $this->tableSizeMb($table);
            $delta = round($before - $after, 1);
            $optimized[] = [
                'name' => $table,
                'before_mb' => $before,
                'after_mb' => $after,
                'delta_mb' => $delta,
            ];
            $this->emitProgress($onProgress, [
                'event' => 'step',
                'key' => 'optimize_'.$table,
                'message' => $delta > 0
                    ? "Optimized {$table}: {$before} → {$after} MB (−{$delta} MB)"
                    : "Optimized {$table}: still {$after} MB (no reclaim — delete old rows first, or table was already compact)",
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
