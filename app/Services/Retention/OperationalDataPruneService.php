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

class OperationalDataPruneService
{
    public function __construct(protected SaleHardDeleteService $saleHardDelete) {}

    /**
     * @return array<string, int>
     */
    public function pruneAll(bool $dryRun = false): array
    {
        return [
            'released_stock_reservations' => $this->pruneReleasedStockReservations($dryRun),
            'kra_agent_commands_completed' => $this->pruneKraAgentCommands('completed', $this->completedKraDays(), $dryRun),
            'kra_agent_commands_failed' => $this->pruneKraAgentCommands('failed', $this->failedKraDays(), $dryRun),
            'kra_agent_commands_expired' => $this->pruneKraAgentCommands('expired', $this->failedKraDays(), $dryRun),
            // Completed Hikvision commands are deleted on delivery; this sweeps leftovers / race rows.
            'hikvision_agent_commands_completed' => $this->pruneHikvisionAgentCommands('completed', $this->completedHikvisionDays(), $dryRun),
            'hikvision_agent_commands_failed' => $this->pruneHikvisionAgentCommands('failed', $this->failedHikvisionDays(), $dryRun),
            'hikvision_agent_commands_expired' => $this->pruneHikvisionAgentCommands('expired', $this->failedHikvisionDays(), $dryRun),
            'hikvision_access_events' => $this->pruneHikvisionAccessEvents($dryRun),
            'forgotten_clock_out_alerts' => $this->pruneForgottenClockOutAlerts($dryRun),
            'employee_clock_sessions' => $this->pruneOldEmployeeClockSessions($dryRun),
            'employee_attendance' => $this->pruneOldEmployeeAttendance($dryRun),
            'audit_logs' => $this->pruneAuditLogs($dryRun),
            'cancelled_sales' => $this->pruneTerminalSales('cancelled', 'cancelled_at', $this->cancelledSalesDays(), $dryRun),
            'expired_sales' => $this->pruneTerminalSales('expired', 'expired_at', $this->expiredSalesDays(), $dryRun),
        ];
    }

    public function pruneReleasedStockReservations(bool $dryRun = false): int
    {
        if (! Schema::hasTable('stock_reservations')) {
            return 0;
        }

        $days = max(1, (int) config('data_retention.released_stock_reservations_days', 14));
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
     * All terminal punch logs — applied, missed (outside window), duplicates, etc. — after 7 days.
     */
    public function pruneHikvisionAccessEvents(bool $dryRun = false): int
    {
        if (! Schema::hasTable('hikvision_access_events')) {
            return 0;
        }

        $days = max(1, (int) config('data_retention.hikvision_access_events_days', 7));
        $cutoff = Carbon::now()->subDays($days);
        $query = HikvisionAccessEvent::query()->where('event_time', '<', $cutoff);

        return $this->deleteInBatches($query, $dryRun);
    }

    /**
     * Drop “forgotten clock-out” from Missed Punches after 7 days (keep the session for attendance).
     */
    public function pruneForgottenClockOutAlerts(bool $dryRun = false): int
    {
        if (! Schema::hasTable('employee_clock_sessions')
            || ! Schema::hasColumn('employee_clock_sessions', 'needs_reconciliation')) {
            return 0;
        }

        $days = max(1, (int) config('data_retention.hikvision_access_events_days', 7));
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

    public function pruneOldEmployeeClockSessions(bool $dryRun = false): int
    {
        if (! Schema::hasTable('employee_clock_sessions')) {
            return 0;
        }

        $cutoff = $this->attendanceCutoffDate()->startOfDay();
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

    public function pruneOldEmployeeAttendance(bool $dryRun = false): int
    {
        if (! Schema::hasTable('employee_attendance')) {
            return 0;
        }

        $cutoff = $this->attendanceCutoffDate()->toDateString();
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

    public function pruneAuditLogs(bool $dryRun = false): int
    {
        if (! Schema::hasTable('audit_logs')) {
            return 0;
        }

        $days = max(1, (int) config('data_retention.audit_logs_days', 10));
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
     *   tables: list<array{name: string, mb: float, rows: int}>
     * }
     */
    public function platformStatus(): array
    {
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

        return [
            'retention' => [
                'hikvision_access_events_days' => (int) config('data_retention.hikvision_access_events_days', 7),
                'attendance_days' => (int) config('data_retention.attendance_days', 60),
                'hikvision_agent_commands_completed_days' => (int) config('data_retention.hikvision_agent_commands_completed_days', 1),
                'hikvision_agent_commands_failed_days' => (int) config('data_retention.hikvision_agent_commands_failed_days', 2),
                'kra_agent_commands_completed_days' => (int) config('data_retention.kra_agent_commands_completed_days', 30),
                'released_stock_reservations_days' => (int) config('data_retention.released_stock_reservations_days', 14),
                'audit_logs_days' => (int) config('data_retention.audit_logs_days', 10),
            ],
            'schedule_time' => (string) config('data_retention.prune_time', '03:40'),
            'tables' => $sizes,
        ];
    }

    /**
     * Reclaim disk after large deletes (optional; can lock tables briefly).
     *
     * @return list<string>
     */
    public function optimizeRetentionTables(): array
    {
        $optimized = [];
        foreach ([
            'hikvision_agent_commands',
            'hikvision_access_events',
            'employee_attendance',
            'employee_clock_sessions',
            'kra_agent_commands',
            'stock_reservations',
            'audit_logs',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            \Illuminate\Support\Facades\DB::statement('OPTIMIZE TABLE `'.$table.'`');
            $optimized[] = $table;
        }

        return $optimized;
    }

    protected function attendanceCutoffDate(): Carbon
    {
        $days = max(30, (int) config('data_retention.attendance_days', 60));

        return Carbon::now()->subDays($days);
    }

    protected function completedKraDays(): int
    {
        return max(1, (int) config('data_retention.kra_agent_commands_completed_days', 30));
    }

    protected function failedKraDays(): int
    {
        return max(1, (int) config('data_retention.kra_agent_commands_failed_days', 7));
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
