<?php

namespace App\Services\Background;

use App\Support\AttendanceSourceLabels;

class EmployeeAttendanceListExportMapper implements ListExportRowMapper
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function mapBatch(array $rows): array
    {
        return array_map(function (array $row): array {
            $employee = is_array($row['employee'] ?? null) ? $row['employee'] : [];
            $branch = is_array($row['branch'] ?? null) ? $row['branch'] : [];

            return [
                'attendance_date' => $row['attendance_date'] ?? '',
                'employee_name' => $employee['full_name'] ?? $employee['first_name'] ?? '',
                'employee_code' => $employee['employee_code'] ?? '',
                'branch_name' => $branch['branch_name'] ?? '',
              'check_in' => $row['clock_in'] ?? $row['check_in'] ?? '',
              'lunch_out' => $row['lunch_out'] ?? '',
              'lunch_in' => $row['lunch_in'] ?? '',
              'check_out' => $row['clock_out'] ?? $row['check_out'] ?? '',
                'hours_worked' => $row['hours_worked'] ?? '',
                'status' => $row['status'] ?? '',
                'login_channel' => $row['login_channel_label'] ?? AttendanceSourceLabels::channelLabel($row['source'] ?? null),
                'source' => $row['source_label'] ?? AttendanceSourceLabels::label($row['source'] ?? null),
                'notes' => $row['notes'] ?? '',
            ];
        }, $rows);
    }
}
