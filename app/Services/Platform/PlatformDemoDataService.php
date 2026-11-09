<?php

namespace App\Services\Platform;

use App\Models\Branch;
use App\Models\Driving\DrivingAssessment;
use App\Models\Driving\DrivingAttendanceLog;
use App\Models\Driving\DrivingCourse;
use App\Models\Driving\DrivingCoursePackage;
use App\Models\Driving\DrivingEnrolment;
use App\Models\Driving\DrivingInstructor;
use App\Models\Driving\DrivingInvoice;
use App\Models\Driving\DrivingLesson;
use App\Models\Driving\DrivingPayment;
use App\Models\Driving\DrivingStudent;
use App\Models\Driving\DrivingVehicle;
use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Role;
use App\Models\User;
use App\Services\Hospitality\HospitalityDemoDataSeeder;
use App\Services\Organization\OrganizationReferenceDataService;
use App\Services\OrganizationProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Creates tagged demo tenants a platform admin can remove in one step.
 * Never calls DemoDataSeeder: that seeder truncates the whole database.
 */
class PlatformDemoDataService
{
    public const PASSWORD = 'password';

    public const USERNAME = 'admin';

    /** @var list<array{company_code: string, profile: string, org_name: string, email: string, admin_name: string, summary: string}> */
    public const ACCOUNTS = [
        [
            'company_code' => 'DRIVE',
            'profile' => 'driving_school',
            'org_name' => 'Demo Driving School',
            'email' => 'drive@demo.centrix.test',
            'admin_name' => 'Stephen Kamau',
            'summary' => 'Students, courses, packages, today’s lessons, attendance, and payments.',
        ],
        [
            'company_code' => 'SPA',
            'profile' => 'spa_kinyozi',
            'org_name' => 'Demo Spa & Kinyozi',
            'email' => 'spa@demo.centrix.test',
            'admin_name' => 'Amina Hassan',
            'summary' => 'Salon workspace. Sample appointments load in the browser the first time you open it.',
        ],
        [
            'company_code' => 'HOTEL',
            'profile' => 'hotel_bar',
            'org_name' => 'Demo Hotel & Bar',
            'email' => 'hotel@demo.centrix.test',
            'admin_name' => 'Peter Otieno',
            'summary' => 'Hotel POS menu, outlets, and floor tables.',
        ],
        [
            'company_code' => 'RETAIL',
            'profile' => 'small_shop',
            'org_name' => 'Demo Retail Shop',
            'email' => 'retail@demo.centrix.test',
            'admin_name' => 'Grace Wanjiku',
            'summary' => 'Retail products, point-of-sale stock, and sample product photos.',
        ],
        [
            'company_code' => 'WHOLESALE',
            'profile' => 'wholesale_retail',
            'org_name' => 'Demo Wholesale Shop',
            'email' => 'wholesale@demo.centrix.test',
            'admin_name' => 'David Mwangi',
            'summary' => 'Bulk products, wholesale stock levels, and sample product photos.',
        ],
        [
            'company_code' => 'DISTRIB',
            'profile' => 'distribution',
            'org_name' => 'Demo Distribution',
            'email' => 'distribution@demo.centrix.test',
            'admin_name' => 'Mary Achieng',
            'summary' => 'Distributor product catalogue, warehouse stock, and sample product photos.',
        ],
    ];

    public function status(): array
    {
        $existing = $this->managedOrganizations()->keyBy('company_code');
        $generatedAt = null;
        foreach ($existing as $org) {
            $generatedAt = data_get($org->module_settings, 'platform_demo.generated_at') ?: $generatedAt;
        }

        return [
            'loaded' => $existing->isNotEmpty(),
            'generated_at' => $generatedAt,
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
            'organizations' => collect(self::ACCOUNTS)->map(function (array $account) use ($existing) {
                return [
                    'company_code' => $account['company_code'],
                    'org_name' => $account['org_name'],
                    'admin_name' => $account['admin_name'],
                    'username' => self::USERNAME,
                    'profile' => $account['profile'],
                    'summary' => $account['summary'],
                    'present' => $existing->has($account['company_code']),
                ];
            })->values()->all(),
        ];
    }

    public function generate(): array
    {
        DB::transaction(function () {
            $this->assertCodesAvailable();
            $this->purgeManagedOrganizations();
            foreach (self::ACCOUNTS as $account) {
                $tenant = $this->createTenant($account);
                if ($account['profile'] === 'driving_school') {
                    $this->seedDrivingSchool($tenant['organization'], $tenant['branch'], $tenant['user']);
                }
                if ($account['profile'] === 'hotel_bar') {
                    app(HospitalityDemoDataSeeder::class)->seedForOrganization(
                        $tenant['organization'],
                        $tenant['user'],
                    );
                }
                if (data_get(config('erp.profiles.'.$account['profile']), 'industry') === 'commerce') {
                    app(CommerceDemoDataSeeder::class)->seedForOrganization(
                        $tenant['organization'],
                        $tenant['branch'],
                        $tenant['user'],
                    );
                }
            }
        });

        return $this->status();
    }

    public function rollback(): array
    {
        $removed = DB::transaction(fn () => $this->purgeManagedOrganizations());

        return [
            'removed' => $removed,
            ...$this->status(),
        ];
    }

    protected function assertCodesAvailable(): void
    {
        $codes = array_column(self::ACCOUNTS, 'company_code');
        $blocked = Organization::withTrashed()
            ->whereIn('company_code', $codes)
            ->get()
            ->filter(fn (Organization $org) => data_get($org->module_settings, 'platform_demo.managed') !== true)
            ->pluck('company_code')
            ->unique()
            ->values();

        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'demo' => [
                    'Company code '.$blocked->join(', ').' already belongs to an organisation. This action only replaces demo organisations.',
                ],
            ]);
        }
    }

    protected function purgeManagedOrganizations(): int
    {
        $organizations = $this->managedOrganizations();
        if ($organizations->isEmpty()) {
            return 0;
        }

        foreach ($organizations as $org) {
            if ($org->deployment_profile === 'hotel_bar') {
                app(HospitalityDemoDataSeeder::class)->removeForOrganization($org);
            }
            if (data_get(config('erp.profiles.'.$org->deployment_profile), 'industry') === 'commerce') {
                app(CommerceDemoDataSeeder::class)->removeForOrganization($org);
            }
        }

        $ids = $organizations->pluck('id')->map(fn ($id) => (int) $id)->all();
        Schema::disableForeignKeyConstraints();
        try {
            $userIds = DB::table('users')->whereIn('organization_id', $ids)->pluck('id')->all();
            if ($userIds !== [] && Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();
            }
            if ($userIds !== []) {
                foreach ($this->baseTablesWithColumn('user_id') as $table) {
                    if ($table === 'users') {
                        continue;
                    }
                    DB::table($table)->whereIn('user_id', $userIds)->delete();
                }
            }
            foreach ($this->baseTablesWithColumn('organization_id') as $table) {
                if ($table === 'organizations') {
                    continue;
                }
                DB::table($table)->whereIn('organization_id', $ids)->delete();
            }
            DB::table('organizations')->whereIn('id', $ids)->delete();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return count($ids);
    }

    /**
     * @param  array{company_code: string, profile: string, org_name: string, email: string, admin_name: string, summary: string}  $account
     * @return array{organization: Organization, branch: Branch, user: User}
     */
    protected function createTenant(array $account): array
    {
        $modules = config('erp.profiles.'.$account['profile'].'.modules', []);
        $org = Organization::query()->create([
            'company_code' => $account['company_code'],
            'org_name' => $account['org_name'],
            'org_email' => $account['email'],
            'primary_tel' => '0700000000',
            'org_address' => 'Demo address',
            'deployment_profile' => $account['profile'],
            'enabled_modules' => is_array($modules) ? $modules : [],
            'module_settings' => [],
            'is_active' => true,
        ]);

        app(OrganizationProvisioningService::class)->syncModuleSettingsFromEnabledModules($org);
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $settings['platform_demo'] = [
            'managed' => true,
            'generated_at' => now()->toIso8601String(),
        ];
        $org->module_settings = $settings;
        $org->save();

        PlatformSubscription::query()->create([
            'organization_id' => $org->id,
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addYear(),
            'renewal_price' => 0,
            'amount' => 0,
            'currency' => 'KES',
        ]);

        $branch = Branch::query()->create([
            'organization_id' => $org->id,
            'branch_code' => 'HQ',
            'branch_name' => 'Head office',
            'branch_type' => 'retail',
            'branch_phone' => '0700000000',
            'is_active' => true,
        ]);

        app(OrganizationReferenceDataService::class)->seedForOrganization((int) $org->id);

        $roleId = Role::query()
            ->whereNull('organization_id')
            ->where('role_name', 'Organisation Admin')
            ->value('id');
        if (! $roleId) {
            $roleId = Role::query()->create([
                'organization_id' => $org->id,
                'role_name' => 'Organisation Admin',
                'scope' => 'org',
                'is_active' => true,
            ])->id;
        }

        $user = User::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'role_id' => $roleId,
            'username' => self::USERNAME,
            'email' => $account['email'],
            'password' => Hash::make(self::PASSWORD),
            'full_name' => $account['admin_name'],
            'is_admin' => true,
            'is_super_admin' => false,
            'access_scope' => 'org',
            'login_channels' => ['backoffice', 'pos', 'mobile', 'manager'],
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        return [
            'organization' => $org->fresh(),
            'branch' => $branch,
            'user' => $user,
        ];
    }

    public function seedOrganization(Organization $org): array
    {
        $profile = (string) $org->deployment_profile;
        $branch = Branch::query()->where('organization_id', $org->id)->orderBy('id')->first();
        $admin = User::query()
            ->where('organization_id', $org->id)
            ->where('is_admin', true)
            ->orderBy('id')
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'demo' => ['Add a branch before generating demo data for this organisation.'],
            ]);
        }
        if (! $admin) {
            throw ValidationException::withMessages([
                'demo' => ['Add an administrator before generating demo data for this organisation.'],
            ]);
        }

        if ($profile === 'driving_school') {
            DB::transaction(function () use ($org, $branch, $admin) {
                $this->clearDrivingDemo($org);
                $this->seedDrivingSchool($org, $branch, $admin, 'DEMO-');
            });
            $this->stampOrganization($org, $profile);

            return [
                'profile' => $profile,
                'message' => 'Sample students, lessons, vehicles, and payments were added to this driving school. Existing records were left in place.',
                ...$this->organizationStatus($org),
            ];
        }

        if ($profile === 'hotel_bar') {
            $seeder = app(HospitalityDemoDataSeeder::class);
            $seeder->removeForOrganization($org);
            $result = $seeder->seedForOrganization($org, $admin);
            $this->stampOrganization($org, $profile);

            return [
                'profile' => $profile,
                'message' => sprintf(
                    'Seeded %d Hotel & Bar menu items with photos, plus %d floor tables for POS testing.',
                    $result['products'] ?? 0,
                    $result['tables'] ?? 0,
                ),
                ...$result,
                ...$this->organizationStatus($org),
            ];
        }

        if (data_get(config('erp.profiles.'.$profile), 'industry') === 'commerce') {
            $result = app(CommerceDemoDataSeeder::class)->seedForOrganization($org, $branch, $admin);
            $this->stampOrganization($org, $profile);
            $catalogue = (string) ($result['catalogue'] ?? 'commerce samples');

            return [
                'profile' => $profile,
                'message' => sprintf(
                    'Seeded %d %s with %d photos, %d suppliers, %d customers, %d sample sales%s, and opening stock for this %s organisation.',
                    $result['products'],
                    $catalogue,
                    $result['images'],
                    $result['suppliers'] ?? 0,
                    $result['customers'] ?? 0,
                    $result['sales'] ?? 0,
                    ! empty($result['distribution']) ? ', plus a demo route / driver / vehicle' : '',
                    config('erp.profiles.'.$profile.'.label') ?: $profile,
                ),
                ...$result,
                ...$this->organizationStatus($org),
            ];
        }

        if ($profile === 'spa_kinyozi') {
            $this->stampOrganization($org, $profile);

            return [
                'profile' => $profile,
                'message' => 'SPA & Kinyozi keeps sample appointments, services, and clients in its browser workspace (open the SPA app once to load them). This organisation profile does not use product photos.',
                ...$this->organizationStatus($org),
            ];
        }

        throw ValidationException::withMessages([
            'demo' => ['Demo data is not configured for this organisation profile.'],
        ]);
    }

    public function clearOrganization(Organization $org): array
    {
        $profile = (string) $org->deployment_profile;
        if ($profile === 'driving_school') {
            DB::transaction(fn () => $this->clearDrivingDemo($org));
        } elseif ($profile === 'hotel_bar') {
            app(HospitalityDemoDataSeeder::class)->removeForOrganization($org);
        } elseif (data_get(config('erp.profiles.'.$profile), 'industry') === 'commerce') {
            app(CommerceDemoDataSeeder::class)->removeForOrganization($org);
        }

        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        unset($settings['platform_demo']);
        $org->module_settings = $settings;
        $org->save();

        return [
            'profile' => $profile,
            'message' => 'Demo records for this organisation were removed. The organisation itself was kept.',
            ...$this->organizationStatus($org->fresh()),
        ];
    }

    public function organizationStatus(Organization $org): array
    {
        return [
            'generated_at' => data_get($org->module_settings, 'platform_demo.generated_at'),
            'profile' => $org->deployment_profile,
        ];
    }

    protected function stampOrganization(Organization $org, string $profile): void
    {
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $settings['platform_demo'] = [
            'managed' => false,
            'profile' => $profile,
            'generated_at' => now()->toIso8601String(),
        ];
        $org->module_settings = $settings;
        $org->save();
    }

    protected function clearDrivingDemo(Organization $org): void
    {
        $studentIds = DrivingStudent::query()
            ->where('organization_id', $org->id)
            ->where('student_number', 'like', 'DEMO-%')
            ->pluck('id');
        $courseIds = DrivingCourse::query()
            ->where('organization_id', $org->id)
            ->where('code', 'like', 'DEMO-%')
            ->pluck('id');

        if ($studentIds->isNotEmpty()) {
            $invoiceIds = DrivingInvoice::query()->whereIn('student_id', $studentIds)->pluck('id');
            if ($invoiceIds->isNotEmpty() && Schema::hasTable('driving_refunds')) {
                DB::table('driving_refunds')->whereIn('invoice_id', $invoiceIds)->delete();
            }
            DrivingPayment::query()->whereIn('student_id', $studentIds)->delete();
            if ($invoiceIds->isNotEmpty()) {
                DrivingInvoice::query()->whereIn('id', $invoiceIds)->delete();
            }
            DrivingLesson::query()->whereIn('student_id', $studentIds)->delete();
            DrivingAssessment::query()->whereIn('student_id', $studentIds)->delete();
            DrivingAttendanceLog::query()->whereIn('student_id', $studentIds)->delete();
            DrivingEnrolment::query()->whereIn('student_id', $studentIds)->delete();
            DrivingStudent::query()->whereIn('id', $studentIds)->delete();
        }

        DrivingInstructor::query()
            ->where('organization_id', $org->id)
            ->where('instructor_number', 'like', 'DEMO-%')
            ->delete();
        DrivingVehicle::query()
            ->where('organization_id', $org->id)
            ->where('registration_number', 'like', 'DEMO %')
            ->delete();
        if ($courseIds->isNotEmpty()) {
            DrivingCoursePackage::query()->whereIn('course_id', $courseIds)->delete();
            DrivingCourse::query()->whereIn('id', $courseIds)->delete();
        }
    }

    protected function seedDrivingSchool(Organization $org, Branch $branch, User $admin, string $prefix = ''): void
    {
        $course = DrivingCourse::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'code' => $prefix.'B',
            'name' => 'Class B',
            'licence_category' => 'B',
            'description' => 'Light vehicle course for new drivers.',
            'theory_lessons' => 8,
            'practical_lessons' => 16,
            'lesson_minutes' => 60,
            'price' => 25000,
            'registration_fee' => 2000,
            'instalment_count' => 3,
            'is_active' => true,
        ]);

        $standard = DrivingCoursePackage::query()->create([
            'organization_id' => $org->id,
            'course_id' => $course->id,
            'name' => 'Standard',
            'price' => 25000,
            'registration_fee' => 2000,
            'theory_lessons' => 8,
            'practical_lessons' => 16,
            'is_active' => true,
        ]);
        $weekend = DrivingCoursePackage::query()->create([
            'organization_id' => $org->id,
            'course_id' => $course->id,
            'name' => 'Weekend',
            'price' => 28000,
            'registration_fee' => 2000,
            'theory_lessons' => 8,
            'practical_lessons' => 16,
            'is_active' => true,
        ]);

        $john = DrivingInstructor::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'instructor_number' => $prefix.'INS-001',
            'full_name' => 'John Kamau',
            'phone' => '0712000001',
            'licence_info' => 'NTSA instructor, class B',
            'status' => 'active',
        ]);
        $maryInstructor = DrivingInstructor::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'instructor_number' => $prefix.'INS-002',
            'full_name' => 'Mary Wanjiku',
            'phone' => '0712000002',
            'licence_info' => 'NTSA instructor, class B',
            'status' => 'active',
        ]);

        $axio = DrivingVehicle::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'registration_number' => $prefix !== '' ? 'DEMO 123A' : 'KDS 123A',
            'make' => 'Toyota',
            'model' => 'Axio',
            'year' => 2020,
            'category' => 'B',
            'transmission' => 'manual',
            'status' => 'available',
        ]);
        $vitz = DrivingVehicle::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'registration_number' => $prefix !== '' ? 'DEMO 456B' : 'KDS 456B',
            'make' => 'Toyota',
            'model' => 'Vitz',
            'year' => 2019,
            'category' => 'B',
            'transmission' => 'automatic',
            'status' => 'available',
        ]);

        $students = [
            $this->drivingStudent($org, $branch, $john, $prefix.'STU-1001', 'Mary Akinyi', '0711000001', now()->toDateString()),
            $this->drivingStudent($org, $branch, $maryInstructor, $prefix.'STU-1002', 'James Kariuki', '0711000002', now()->toDateString()),
            $this->drivingStudent($org, $branch, $john, $prefix.'STU-1003', 'Grace Njeri', '0711000003', now()->subDays(5)->toDateString()),
            $this->drivingStudent($org, $branch, $john, $prefix.'STU-1004', 'Peter Mwangi', '0711000004', now()->subDays(10)->toDateString()),
            $this->drivingStudent($org, $branch, $maryInstructor, $prefix.'STU-1005', 'Faith Wanjiku', '0711000005', now()->subDays(3)->toDateString()),
        ];

        $enrolments = [];
        foreach ($students as $index => $student) {
            $package = $index === 1 ? $weekend : $standard;
            $enrolments[] = DrivingEnrolment::query()->create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'student_id' => $student->id,
                'course_id' => $course->id,
                'package_id' => $package->id,
                'status' => 'active',
                'enrolled_on' => $student->registered_on,
                'price' => $package->price,
                'registration_fee' => $package->registration_fee,
                'lessons_required' => 24,
                'lessons_completed' => $index === 0 ? 6 : 1,
            ]);
        }

        $day = now()->startOfDay();
        $lessons = [
            [$students[0], $enrolments[0], $john, $axio, 'practical', 8, 'completed'],
            [$students[1], $enrolments[1], $maryInstructor, $vitz, 'practical', 11, 'scheduled'],
            [$students[2], $enrolments[2], $john, null, 'theory', 14, 'scheduled'],
            [$students[3], $enrolments[3], $john, $axio, 'practical', 16, 'confirmed'],
        ];
        foreach ($lessons as [$student, $enrolment, $instructor, $vehicle, $type, $hour, $status]) {
            $starts = $day->copy()->addHours($hour);
            DrivingLesson::query()->create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'student_id' => $student->id,
                'enrolment_id' => $enrolment->id,
                'instructor_id' => $instructor->id,
                'vehicle_id' => $vehicle?->id,
                'lesson_type' => $type,
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addHour(),
                'location' => $type === 'theory' ? 'Classroom 1' : 'Yard',
                'status' => $status,
                'attendance' => $status === 'completed' ? 'present' : null,
            ]);
        }

        DrivingAttendanceLog::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'student_id' => $students[0]->id,
            'direction' => 'in',
            'recorded_at' => $day->copy()->addHours(7)->addMinutes(50),
            'verification' => 'none',
            'recorded_by' => $admin->id,
        ]);

        $maryInvoice = DrivingInvoice::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'student_id' => $students[0]->id,
            'enrolment_id' => $enrolments[0]->id,
            'invoice_number' => $prefix.'DRV-1001',
            'kind' => 'tuition',
            'amount' => 25000,
            'discount' => 0,
            'status' => 'open',
            'issued_on' => now()->toDateString(),
        ]);
        DrivingPayment::query()->create([
            'organization_id' => $org->id,
            'invoice_id' => $maryInvoice->id,
            'student_id' => $students[0]->id,
            'amount' => 10000,
            'method' => 'mpesa',
            'reference' => 'DEMO-MPESA',
            'received_on' => now()->toDateString(),
            'received_by' => $admin->id,
            'provider_confirmed' => true,
        ]);
        DrivingInvoice::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'student_id' => $students[1]->id,
            'enrolment_id' => $enrolments[1]->id,
            'invoice_number' => $prefix.'DRV-1002',
            'kind' => 'tuition',
            'amount' => 28000,
            'discount' => 0,
            'status' => 'open',
            'issued_on' => now()->toDateString(),
        ]);

        DrivingAssessment::query()->create([
            'organization_id' => $org->id,
            'student_id' => $students[2]->id,
            'enrolment_id' => $enrolments[2]->id,
            'instructor_id' => $john->id,
            'kind' => 'theory',
            'title' => 'Highway code quiz',
            'score' => 82,
            'max_score' => 100,
            'passed' => true,
            'assessed_on' => now()->toDateString(),
            'official_exam' => false,
        ]);
    }

    protected function drivingStudent(
        Organization $org,
        Branch $branch,
        DrivingInstructor $instructor,
        string $number,
        string $name,
        string $phone,
        string $registeredOn,
    ): DrivingStudent {
        return DrivingStudent::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'student_number' => $number,
            'full_name' => $name,
            'phone' => $phone,
            'preferred_instructor_id' => $instructor->id,
            'registered_on' => $registeredOn,
            'status' => 'active',
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Organization> */
    protected function managedOrganizations()
    {
        return Organization::withTrashed()
            ->where('module_settings->platform_demo->managed', true)
            ->get()
            ->values();
    }

    /** @return list<string> */
    protected function baseTablesWithColumn(string $column): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return collect(Schema::getTables())
                ->pluck('name')
                ->filter(fn (string $table) => Schema::hasColumn($table, $column))
                ->values()
                ->all();
        }

        $rows = DB::select(
            'SELECT c.TABLE_NAME AS name
             FROM information_schema.COLUMNS c
             INNER JOIN information_schema.TABLES t
               ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = DATABASE()
               AND c.COLUMN_NAME = ?
               AND t.TABLE_TYPE = ?',
            [$column, 'BASE TABLE'],
        );

        return array_map(fn ($row) => (string) $row->name, $rows);
    }
}
