<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\MobileRepAttendanceSession;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Payroll\PayrollEarningsService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileFieldAttendanceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_sign_in_and_sign_out_when_feature_enabled(): void
    {
        Storage::fake('public');

        $user = $this->makeMobileUser();
        $this->enableFieldAttendance($user);
        $token = $this->loginMobile($user);

        $signIn = $this->withToken($token)->post('/api/v1/mobile/attendance/sign-in', [
            'photo' => UploadedFile::fake()->image('sign-in.jpg'),
            'latitude' => -1.292100,
            'longitude' => 36.821900,
            'address' => 'Nairobi, Kenya',
        ]);

        $signIn->assertCreated()
            ->assertJsonPath('session.is_open', true);

        $sessionId = $signIn->json('session.id');
        $this->assertDatabaseHas('mobile_rep_attendance_sessions', [
            'id' => $sessionId,
            'user_id' => $user->id,
            'sign_out_at' => null,
        ]);

        $signOut = $this->withToken($token)->post('/api/v1/mobile/attendance/sign-out', [
            'photo' => UploadedFile::fake()->image('sign-out.jpg'),
            'latitude' => -1.292200,
            'longitude' => 36.822000,
            'address' => 'Nairobi CBD',
        ]);

        $signOut->assertOk()
            ->assertJsonPath('session.is_open', false);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/attendance/summary')
            ->assertOk()
            ->assertJsonPath('feature_enabled', true)
            ->assertJsonPath('sessions_today', 1)
            ->assertJsonPath('completed_sessions_today', 1);

        $admin = User::where('username', 'admin')->firstOrFail();
        $adminToken = $this->postJson('/api/v1/auth/login', [
            'company_code' => Organization::findOrFail($admin->organization_id)->company_code,
            'username' => 'admin',
            'password' => 'password',
            'client_id' => 'web',
            'login_channel' => 'web',
        ])->json('token');

        $this->withToken($adminToken)
            ->getJson('/api/v1/sales/mobile-field-attendance')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.work_hours', fn ($value) => $value > 0);

        $this->assertDatabaseHas('mobile_rep_attendance_sessions', [
            'id' => $sessionId,
            'sign_out_latitude' => -1.2922,
            'close_reason' => 'sign_out',
        ]);
    }

    public function test_sign_out_syncs_hr_attendance_for_linked_employee(): void
    {
        Storage::fake('public');
        $this->travelTo('2026-06-16 09:00:00');

        $user = $this->makeMobileUser();
        $employee = $this->linkEmployeeToUser($user);
        $this->enableFieldAttendance($user);
        $token = $this->loginMobile($user);

        $this->withToken($token)->post('/api/v1/mobile/attendance/sign-in', [
            'photo' => UploadedFile::fake()->image('sign-in.jpg'),
            'latitude' => -1.292100,
            'longitude' => 36.821900,
        ])->assertCreated();

        $this->withToken($token)->post('/api/v1/mobile/attendance/sign-out', [
            'photo' => UploadedFile::fake()->image('sign-out.jpg'),
            'latitude' => -1.292200,
            'longitude' => 36.822000,
        ])->assertOk();

        $today = '2026-06-16';

        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $employee->id,
            'attendance_date' => $today,
            'source' => 'field_rep',
            'status' => 'present',
        ]);

        $attendance = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->firstOrFail();

        $this->assertSame('Field rep', $attendance->source_label);
        $this->assertGreaterThan(0, (float) $attendance->hours_worked);

        $summary = app(PayrollEarningsService::class)->summarizeAttendance(
            $employee->fresh(),
            $today,
            $today,
        );

        $this->assertSame(1.0, $summary['attended_days']);
    }

    public function test_hr_linkage_flags_unlinked_rep_with_sessions(): void
    {
        Storage::fake('public');
        $this->travelTo('2026-06-16 09:00:00');

        $user = $this->makeMobileUser();
        $this->enableFieldAttendance($user);
        $token = $this->loginMobile($user);

        $this->withToken($token)->post('/api/v1/mobile/attendance/sign-in', [
            'photo' => UploadedFile::fake()->image('sign-in.jpg'),
            'latitude' => -1.292100,
            'longitude' => 36.821900,
        ])->assertCreated();

        $admin = User::where('username', 'admin')->firstOrFail();
        $adminToken = $this->postJson('/api/v1/auth/login', [
            'company_code' => Organization::findOrFail($admin->organization_id)->company_code,
            'username' => 'admin',
            'password' => 'password',
            'client_id' => 'web',
            'login_channel' => 'web',
        ])->json('token');

        $this->withToken($adminToken)
            ->getJson('/api/v1/attendance/field-rep-hr-linkage?days=30')
            ->assertOk()
            ->assertJsonPath('attention_needed', true)
            ->assertJsonPath('unlinked_rep_count', 1)
            ->assertJsonPath('reps.0.user_id', $user->id)
            ->assertJsonPath('reps.0.status', 'no_employee');

        $this->withToken($adminToken)
            ->getJson('/api/v1/attendance/field-sessions?from_date=2026-06-16&to_date=2026-06-16')
            ->assertOk()
            ->assertJsonPath('hr_linkage.attention_needed', true)
            ->assertJsonPath('data.0.hr_link.counts_toward_payroll', false);
    }

    public function test_suspend_and_resume_same_day_session(): void
    {
        Storage::fake('public');

        $user = $this->makeMobileUser();
        $this->enableFieldAttendance($user);
        $token = $this->loginMobile($user);

        $signIn = $this->withToken($token)->post('/api/v1/mobile/attendance/sign-in', [
            'photo' => UploadedFile::fake()->image('sign-in.jpg'),
            'latitude' => -1.292100,
            'longitude' => 36.821900,
        ]);

        $signIn->assertCreated();
        $sessionId = $signIn->json('session.id');

        $this->withToken($token)
            ->postJson('/api/v1/mobile/attendance/suspend')
            ->assertOk()
            ->assertJsonPath('session.is_suspended', true)
            ->assertJsonPath('session.is_active', false);

        $this->assertDatabaseHas('mobile_rep_attendance_sessions', [
            'id' => $sessionId,
            'sign_out_at' => null,
        ]);
        $this->assertNotNull(
            MobileRepAttendanceSession::findOrFail($sessionId)->suspended_at,
        );

        $this->withToken($token)
            ->postJson('/api/v1/mobile/attendance/resume')
            ->assertOk()
            ->assertJsonPath('session.is_active', true)
            ->assertJsonPath('session.is_suspended', false);

        $this->assertNull(
            MobileRepAttendanceSession::findOrFail($sessionId)->suspended_at,
        );
    }

    public function test_idle_scheduler_closes_suspended_session_with_reason(): void
    {
        Storage::fake('public');

        $user = $this->makeMobileUser();
        $this->enableFieldAttendance($user);
        $token = $this->loginMobile($user);

        $signIn = $this->withToken($token)->post('/api/v1/mobile/attendance/sign-in', [
            'photo' => UploadedFile::fake()->image('sign-in.jpg'),
            'latitude' => -1.292100,
            'longitude' => 36.821900,
        ]);

        $sessionId = $signIn->json('session.id');

        $this->withToken($token)
            ->postJson('/api/v1/mobile/attendance/suspend')
            ->assertOk();

        $session = MobileRepAttendanceSession::findOrFail($sessionId);
        $workBeforeClose = app(\App\Services\Sales\MobileFieldAttendanceService::class)
            ->workSeconds($session);

        $closed = app(\App\Services\Sales\MobileFieldAttendanceService::class)
            ->closeIdleSessions(now());

        $this->assertSame(1, $closed);

        $session->refresh();
        $this->assertNotNull($session->sign_out_at);
        $this->assertSame('idle_end_of_day', $session->close_reason);
        $this->assertSame($workBeforeClose, $session->accumulated_work_seconds);
    }

    public function test_sign_in_rejected_when_feature_disabled(): void
    {
        Storage::fake('public');

        $user = $this->makeMobileUser();
        $token = $this->loginMobile($user);

        $response = $this->withToken($token)->post('/api/v1/mobile/attendance/sign-in', [
            'photo' => UploadedFile::fake()->image('sign-in.jpg'),
            'latitude' => -1.292100,
            'longitude' => 36.821900,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, MobileRepAttendanceSession::query()->count());
    }

    protected function enableFieldAttendance(User $user): void
    {
        $org = Organization::findOrFail($user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'mobile_enable_field_attendance' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function makeMobileUser(array $overrides = []): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        return User::create(array_merge([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'mobile_att_'.uniqid(),
            'email' => uniqid('mobile_att_').'@example.com',
            'password' => Hash::make('password'),
            'full_name' => 'Mobile Attendance Rep',
            'mobile_order_scope' => 'both',
        ], $overrides));
    }

    protected function linkEmployeeToUser(User $user): Employee
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $user->organization_id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $user->organization_id,
            'shift_code' => 'STD',
            'shift_name' => 'Standard day',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'works_saturday' => false,
            'works_sunday' => false,
            'works_public_holidays' => false,
            'is_active' => true,
        ]);

        return Employee::query()->create([
            'organization_id' => $user->organization_id,
            'branch_id' => $user->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'employee_code' => 'EMP#'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#'.strtoupper(uniqid()),
            'first_name' => 'Field',
            'last_name' => 'Rep',
            'full_name' => 'Field Rep',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => now()->toDateString(),
            'base_salary' => 40000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);
    }

    protected function loginMobile(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'company_code' => Organization::findOrFail($user->organization_id)->company_code,
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'mobile',
            'login_channel' => 'mobile',
        ])->json('token');
    }
}
