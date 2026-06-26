<?php

namespace App\Services\Background;

class EmployeeLeaveDayListExportMapper implements ListExportRowMapper
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function mapBatch(array $rows): array
    {
        return array_map(function (array $row): array {
            $employee = is_array($row['employee'] ?? null) ? $row['employee'] : [];

            return [
                'leave_date' => $row['leave_date'] ?? $row['start_date'] ?? '',
                'employee_name' => $employee['full_name'] ?? '',
                'employee_code' => $employee['employee_code'] ?? '',
                'leave_type' => $row['leave_type'] ?? $row['type'] ?? '',
                'status' => $row['status'] ?? '',
                'days' => $row['days'] ?? $row['day_count'] ?? '',
                'reason' => $row['reason'] ?? $row['notes'] ?? '',
            ];
        }, $rows);
    }
}
