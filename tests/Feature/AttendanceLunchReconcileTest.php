<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeClockSession;
use App\Models\EmployeeOvertime;
use App\Models\Organization;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Attendance\AttendanceDayReconciler;
use App\Services\Payroll\PayrollEarningsService;
use Carbon\Carbon;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AttendanceLunchReconcileTest extends TestCase
{
    use RefreshesErpDatabase;

    protected Organization $org;

    protected User $admin;

    protected WorkShift $shift;

    protected Employee $employee;

    /** Fixed Monday for deterministic schedule. */
    protected string $workDate = '2026-07-20';

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $this->org->id)->firstOrFail();

        $this->shift = WorkShift::query()->create([
            'organization_id' => $this->org->id,
            'shift_code' => 'LNCH'.uniqid(),
            'shift_name' => 'Lunch test shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'works_saturday' => false,
            'works_sunday' => false,
            'works_public_holidays' => false,
            'is_active' => true,
        ]);

        $this->employee = Employee::query()->create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $this->shift->id,
            'employee_code' => 'EMP#L'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#L'.strtoupper(uniqid()),
            'first_name' => 'Lunch',
            'last_name' => 'Tester',
            'full_name' => 'Lunch Tester',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 44000,
            'country' => 'Kenya',
            'is_active' => true,
            'bank_lunch_as_work' => false,
        ]);
    }

    protected function addSession(string $in, string $out): EmployeeClockSession
    {
        return EmployeeClockSession::query()->create([
            'employee_id' => $this->employee->id,
            'organization_id' => $this->org->id,
            'branch_id' => $this->employee->branch_id,
            'source' => 'clock_device',
            'clock_in_at' => Carbon::parse($this->workDate.' '.$in),
            'clock_out_at' => Carbon::parse($this->workDate.' '.$out),
        ]);
    }

    public function test_four_punches_lunch_taken_paid_hours_exclude_lunch(): void
    {
        $this->addSession('08:00:00', '13:00:00');
        $this->addSession('14:00:00', '17:00:00');

        $att = app(AttendanceDayReconciler::class)->reconcileFromSessions(
            $this->employee->fresh('shift'),
            $this->workDate,
        );

        $this->assertSame('taken', $att->lunch_status);
        $this->assertSame(60, (int) $att->lunch_minutes);
        $this->assertEquals(9.0, (float) $att->expected_hours);
        $this->assertEquals(9.0, (float) $att->hours_worked);
        $this->assertSame(0, (int) $att->late_minutes);
        $this->assertSame(0, (int) $att->overtime_minutes);
        $this->assertSame('present', $att->status);
    }

    public function test_late_thirty_minutes_reduces_paid_hours(): void
    {
        $this->addSession('08:30:00', '13:00:00');
        $this->addSession('14:00:00', '17:00:00');

        $att = app(AttendanceDayReconciler::class)->reconcileFromSessions(
            $this->employee->fresh('shift'),
            $this->workDate,
        );

        $this->assertSame(30, (int) $att->late_minutes);
        $this->assertEquals(8.5, (float) $att->hours_worked);
        $this->assertSame('late', $att->status);
    }

    public function test_thirty_minutes_past_end_does_not_create_overtime(): void
    {
        $this->addSession('08:00:00', '13:00:00');
        $this->addSession('14:00:00', '17:30:00');

        $att = app(AttendanceDayReconciler::class)->reconcileFromSessions(
            $this->employee->fresh('shift'),
            $this->workDate,
        );

        $this->assertEquals(9.0, (float) $att->hours_worked);
        $this->assertSame(30, (int) $att->overtime_minutes);
        $this->assertSame(0, EmployeeOvertime::query()->where('employee_id', $this->employee->id)->count());
    }

    public function test_sixty_minutes_past_end_creates_pending_overtime(): void
    {
        $this->addSession('08:00:00', '13:00:00');
        $this->addSession('14:00:00', '18:00:00');

        $att = app(AttendanceDayReconciler::class)->reconcileFromSessions(
            $this->employee->fresh('shift'),
            $this->workDate,
        );

        $this->assertEquals(9.0, (float) $att->hours_worked);
        $this->assertSame(60, (int) $att->overtime_minutes);

        $ot = EmployeeOvertime::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('work_date', $this->workDate)
            ->first();

        $this->assertNotNull($ot);
        $this->assertSame('pending', $ot->status);
        $this->assertEquals(1.0, (float) $ot->hours);
        $this->assertStringContainsString('auto_from_attendance', (string) $ot->notes);
    }

    public function test_bank_lunch_skip_and_leave_early_keeps_full_paid_hours(): void
    {
        $this->employee->update(['bank_lunch_as_work' => true]);
        $this->addSession('08:00:00', '16:00:00');

        $att = app(AttendanceDayReconciler::class)->reconcileFromSessions(
            $this->employee->fresh('shift'),
            $this->workDate,
        );

        $this->assertSame('skipped', $att->lunch_status);
        $this->assertEquals(9.0, (float) $att->hours_worked);
        $this->assertSame(0, (int) $att->early_leave_minutes);
    }

    public function test_skip_lunch_without_bank_early_leave_reduces_paid_hours(): void
    {
        $this->addSession('08:00:00', '16:00:00');

        $att = app(AttendanceDayReconciler::class)->reconcileFromSessions(
            $this->employee->fresh('shift'),
            $this->workDate,
        );

        $this->assertSame('skipped', $att->lunch_status);
        $this->assertEquals(8.0, (float) $att->hours_worked);
        $this->assertSame(60, (int) $att->early_leave_minutes);
    }

    public function test_skip_lunch_without_bank_caps_at_expected_and_shows_dash_status(): void
    {
        $this->addSession('08:00:00', '17:00:00');

        $att = app(AttendanceDayReconciler::class)->reconcileFromSessions(
            $this->employee->fresh('shift'),
            $this->workDate,
        );

        $this->assertSame('skipped', $att->lunch_status);
        $this->assertNull($att->lunch_minutes);
        $this->assertEquals(9.0, (float) $att->hours_worked);
        $this->assertEquals(9.0, (float) $att->expected_hours);
    }

    public function test_manual_span_lunch_taken_credits_configured_lunch(): void
    {
        $att = app(AttendanceDayReconciler::class)->reconcileManualSpan(
            $this->employee->fresh('shift'),
            $this->workDate,
            '08:00:00',
            '17:00:00',
            'manual',
            null,
            null,
            null,
            'present',
            true,
        );

        $this->assertSame('taken', $att->lunch_status);
        $this->assertSame(60, (int) $att->lunch_minutes);
        $this->assertEquals(9.0, (float) $att->hours_worked);
        $this->assertEquals(9.0, (float) $att->expected_hours);
        $this->assertSame('present', $att->status);
    }

    public function test_manual_span_lunch_unchecked_marks_skipped(): void
    {
        $att = app(AttendanceDayReconciler::class)->reconcileManualSpan(
            $this->employee->fresh('shift'),
            $this->workDate,
            '08:00:00',
            '17:00:00',
            'manual',
            null,
            null,
            null,
            'present',
            false,
        );

        $this->assertSame('skipped', $att->lunch_status);
        $this->assertNull($att->lunch_minutes);
        $this->assertEquals(9.0, (float) $att->hours_worked);
    }

    public function test_shift_without_lunch_ignores_manual_lunch_taken(): void
    {
        $this->shift->update([
            'lunch_required' => false,
            'lunch_minutes' => 0,
        ]);

        $att = app(AttendanceDayReconciler::class)->reconcileManualSpan(
            $this->employee->fresh('shift'),
            $this->workDate,
            '08:00:00',
            '17:00:00',
            'manual',
            null,
            null,
            null,
            'present',
            true,
        );

        $this->assertSame('-', $att->lunch_status);
        $this->assertNull($att->lunch_minutes);
        $this->assertEquals(9.0, (float) $att->hours_worked);
        $this->assertEquals(9.0, (float) $att->expected_hours);
    }

    public function test_saturday_uses_alternate_lunch_minutes(): void
    {
        $saturday = '2026-07-25'; // Saturday
        $this->shift->update([
            'works_saturday' => true,
            'use_alternate_hours' => true,
            'alternate_start_time' => '08:00:00',
            'alternate_end_time' => '13:00:00',
            'alternate_lunch_minutes' => 30,
            'alternate_lunch_required' => true,
        ]);

        $hours = $this->shift->fresh()->hoursForDate($saturday, false);
        $this->assertTrue($hours['lunch_required']);
        $this->assertSame(30, $hours['lunch_minutes']);
        $this->assertSame('08:00:00', $hours['start_time']);
        $this->assertSame('13:00:00', $hours['end_time']);

        $weekday = $this->shift->fresh()->hoursForDate($this->workDate, false);
        $this->assertSame(60, $weekday['lunch_minutes']);
    }

    public function test_weekend_can_disable_lunch_while_weekday_keeps_it(): void
    {
        $saturday = '2026-07-25';
        $this->shift->update([
            'works_saturday' => true,
            'alternate_lunch_required' => false,
            'alternate_lunch_minutes' => 0,
        ]);

        $hours = $this->shift->fresh()->hoursForDate($saturday, false);
        $this->assertFalse($hours['lunch_required']);
        $this->assertSame(0, $hours['lunch_minutes']);

        $weekday = $this->shift->fresh()->hoursForDate($this->workDate, false);
        $this->assertTrue($weekday['lunch_required']);
        $this->assertSame(60, $weekday['lunch_minutes']);
    }

    public function test_lateness_waiver_restores_paid_hours(): void
    {
        $this->addSession('08:30:00', '13:00:00');
        $this->addSession('14:00:00', '17:00:00');

        $att = app(AttendanceDayReconciler::class)->reconcileFromSessions(
            $this->employee->fresh('shift'),
            $this->workDate,
        );

        $this->assertEquals(8.5, (float) $att->hours_worked);
        $this->assertSame(30, (int) $att->late_minutes);

        $waived = app(AttendanceDayReconciler::class)->setLatenessWaiver(
            $att,
            true,
            'Doctor appointment',
            null,
        );

        $this->assertTrue((bool) $waived->lateness_waived);
        $this->assertEquals(9.0, (float) $waived->hours_worked);
        $this->assertSame('present', $waived->status);
        $this->assertSame('Doctor appointment', $waived->lateness_waiver_reason);
    }

    public function test_payroll_prorates_basic_by_paid_over_expected_hours(): void
    {
        // One late day: 7.5 paid of 8 expected on a single-day period.
        $this->addSession('08:30:00', '13:00:00');
        $this->addSession('14:00:00', '17:00:00');
        app(AttendanceDayReconciler::class)->reconcileFromSessions(
            $this->employee->fresh('shift'),
            $this->workDate,
        );

        $period = PayPeriod::query()->create([
            'organization_id' => $this->org->id,
            'period_code' => 'LNCH'.uniqid(),
            'period_start' => $this->workDate,
            'period_end' => $this->workDate,
            'status' => 'open',
        ]);

        $line = app(PayrollEarningsService::class)->buildLineInput(
            $this->employee->fresh('shift'),
            $period,
            [
                'include_allowances' => false,
                'include_other_deductions' => false,
                'include_overtime' => false,
                'use_attendance_proration' => true,
            ],
        );

        $this->assertNotNull($line);
        $this->assertEquals(8.5, (float) $line['payroll_meta']['paid_hours']);
        $this->assertEquals(9.0, (float) $line['payroll_meta']['expected_hours']);
        // 44000 * (8.5/9) ≈ 41555.56
        $this->assertEquals(41555.56, (float) $line['basic_salary']);
    }

    public function test_payroll_uses_calendar_days_through_today_not_future_absences(): void
    {
        // Mon–Fri worker, paid across calendar days (Sun counts in place).
        // Mid-month run on 24 Jul for 1–31: show 24/31, not future days as absent.
        $this->travelTo('2026-07-24 10:00:00');

        $cursor = Carbon::parse('2026-07-01');
        $today = Carbon::parse('2026-07-24');
        while ($cursor->lte($today)) {
            // Shift is Mon–Fri; create attendance only on scheduled days.
            if ($cursor->isWeekday()) {
                $date = $cursor->toDateString();
                EmployeeClockSession::query()->create([
                    'employee_id' => $this->employee->id,
                    'organization_id' => $this->org->id,
                    'branch_id' => $this->employee->branch_id,
                    'source' => 'clock_device',
                    'clock_in_at' => Carbon::parse($date.' 08:00:00'),
                    'clock_out_at' => Carbon::parse($date.' 17:00:00'),
                ]);
                app(AttendanceDayReconciler::class)->reconcileFromSessions(
                    $this->employee->fresh('shift'),
                    $date,
                );
            }
            $cursor->addDay();
        }

        $summary = app(PayrollEarningsService::class)->summarizeAttendance(
            $this->employee->fresh('shift'),
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame(31.0, $summary['expected_days']);
        $this->assertSame(24.0, $summary['paid_days']);
        $this->assertSame(7.0, $summary['remaining_days']);
        $this->assertSame(0.0, $summary['absent_days']);
        $this->assertGreaterThan(0.0, $summary['rest_days_paid']);
    }
}
