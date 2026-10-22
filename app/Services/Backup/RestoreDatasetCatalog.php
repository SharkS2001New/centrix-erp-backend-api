<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Schema;

/**
 * Named restore packs so platform admins can pick "HR attendance"
 * without memorizing every related table.
 */
class RestoreDatasetCatalog
{
    /**
     * @return list<array{
     *   id: string,
     *   label: string,
     *   description: string,
     *   recommended_mode: 'merge'|'replace',
     *   tables: list<string>,
     *   available_tables: list<string>,
     *   missing_tables: list<string>
     * }>
     */
    public function datasets(): array
    {
        $defs = $this->definitions();
        $out = [];

        foreach ($defs as $def) {
            $available = [];
            $missing = [];
            foreach ($def['tables'] as $table) {
                if (Schema::hasTable($table)) {
                    $available[] = $table;
                } else {
                    $missing[] = $table;
                }
            }

            $out[] = [
                'id' => $def['id'],
                'label' => $def['label'],
                'description' => $def['description'],
                'recommended_mode' => $def['recommended_mode'],
                'tables' => $def['tables'],
                'available_tables' => $available,
                'missing_tables' => $missing,
            ];
        }

        return $out;
    }

    /**
     * Resolve a dataset id (or list of ids) to concrete tables that exist.
     *
     * @param  string|list<string>|null  $dataset
     * @param  list<string>|null  $extraTables
     * @return list<string>|null  Null means full dump (no table filter)
     */
    public function resolveTables(string|array|null $dataset, ?array $extraTables = null): ?array
    {
        $tables = [];

        $ids = [];
        if (is_string($dataset) && trim($dataset) !== '') {
            $ids[] = strtolower(trim($dataset));
        } elseif (is_array($dataset)) {
            foreach ($dataset as $raw) {
                $id = strtolower(trim((string) $raw));
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        $byId = [];
        foreach ($this->definitions() as $def) {
            $byId[$def['id']] = $def['tables'];
        }

        foreach ($ids as $id) {
            if ($id === 'full' || $id === '*') {
                return null;
            }
            if (! isset($byId[$id])) {
                throw new DatabaseRestoreException(
                    'Unknown restore dataset "'.$id.'". Choose one of: '.implode(', ', array_keys($byId)),
                    'invalid_dataset',
                );
            }
            foreach ($byId[$id] as $table) {
                $tables[] = $table;
            }
        }

        if (is_array($extraTables)) {
            foreach ($extraTables as $raw) {
                $name = strtolower(trim((string) $raw));
                if ($name !== '') {
                    $tables[] = $name;
                }
            }
        }

        $tables = array_values(array_unique($tables));
        if ($tables === []) {
            return null;
        }

        // Only restore tables that exist on this server.
        return array_values(array_filter(
            $tables,
            static fn (string $t) => Schema::hasTable($t),
        ));
    }

    /**
     * @return list<array{id: string, label: string, description: string, recommended_mode: string, tables: list<string>}>
     */
    protected function definitions(): array
    {
        return [
            [
                'id' => 'hr_attendance',
                'label' => 'HR attendance (day + clock sessions)',
                'description' => 'Employee day sheets and clock in/out sessions — what the Attendance screen shows for staff.',
                'recommended_mode' => 'merge',
                'tables' => [
                    'employee_attendance',
                    'employee_clock_sessions',
                ],
            ],
            [
                'id' => 'hr_attendance_with_punches',
                'label' => 'HR attendance + device punches',
                'description' => 'Day sheets, clock sessions, and raw Hikvision access events (needed to re-check forgotten clock-outs).',
                'recommended_mode' => 'merge',
                'tables' => [
                    'employee_attendance',
                    'employee_clock_sessions',
                    'hikvision_access_events',
                ],
            ],
            [
                'id' => 'mobile_field_attendance',
                'label' => 'Mobile / field attendance',
                'description' => 'Rep and driver sign-in sessions from the mobile apps.',
                'recommended_mode' => 'merge',
                'tables' => [
                    'mobile_rep_attendance_sessions',
                    'mobile_driver_attendance_sessions',
                ],
            ],
            [
                'id' => 'hikvision_agent',
                'label' => 'Hikvision agent commands',
                'description' => 'Queued/completed device command payloads (not attendance itself).',
                'recommended_mode' => 'merge',
                'tables' => [
                    'hikvision_agent_commands',
                ],
            ],
            [
                'id' => 'kra_agent',
                'label' => 'KRA agent commands',
                'description' => 'Queued/completed KRA eTIMS agent commands.',
                'recommended_mode' => 'merge',
                'tables' => [
                    'kra_agent_commands',
                    'kra_responses',
                ],
            ],
            [
                'id' => 'audit_logs',
                'label' => 'Audit logs',
                'description' => 'Who changed what — usually only needed for investigations.',
                'recommended_mode' => 'merge',
                'tables' => [
                    'audit_logs',
                ],
            ],
            [
                'id' => 'sales_core',
                'label' => 'Sales orders (core)',
                'description' => 'Orders, line items, and payments. Use merge carefully; prefer support guidance for production.',
                'recommended_mode' => 'merge',
                'tables' => [
                    'sales',
                    'sale_items',
                    'sale_payments',
                ],
            ],
        ];
    }
}
