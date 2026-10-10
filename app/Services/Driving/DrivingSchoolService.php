<?php

namespace App\Services\Driving;

use App\Models\Driving\DrivingAssessment;
use App\Models\Driving\DrivingAttendanceLog;
use App\Models\Driving\DrivingCourse;
use App\Models\Driving\DrivingCoursePackage;
use App\Models\Driving\DrivingEnrolment;
use App\Models\Driving\DrivingExpense;
use App\Models\Driving\DrivingInstructor;
use App\Models\Driving\DrivingInvoice;
use App\Models\Driving\DrivingLesson;
use App\Models\Driving\DrivingNotification;
use App\Models\Driving\DrivingPayment;
use App\Models\Driving\DrivingRefund;
use App\Models\Driving\DrivingStudent;
use App\Models\Driving\DrivingVehicle;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DrivingSchoolService
{
    public function __construct(
        protected UserAccessService $access,
        protected AuditLogger $audit,
    ) {}

    public function scoped(Builder $query, User $user, Request $request, string $branchColumn = 'branch_id'): Builder
    {
        $this->access->scopeOrganization($query, $user, 'organization_id', $request);
        $this->access->scopeBranchIfLimited($query, $user, $branchColumn);

        return $query;
    }

    public function branchIdForWrite(User $user, ?int $requested, Request $request): ?int
    {
        $limited = $this->access->branchId($user);
        if ($limited !== null) {
            return $limited;
        }
        if ($requested) {
            $this->access->assertBranchInOrganization($user, $requested, $request);
        }

        return $requested;
    }

    public function nextNumber(string $modelClass, int $organizationId, string $column, string $prefix): string
    {
        $latest = $modelClass::query()
            ->where('organization_id', $organizationId)
            ->where($column, 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value($column);
        $seq = 1;
        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $match)) {
            $seq = ((int) $match[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    public function assertNoDuplicateStudent(int $organizationId, string $phone, ?int $ignoreId = null): void
    {
        $exists = DrivingStudent::query()
            ->where('organization_id', $organizationId)
            ->where('phone', $phone)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'phone' => ['A student with this phone number is already registered.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function bookLesson(Organization $org, User $user, array $input, ?int $ignoreId = null): DrivingLesson
    {
        return DB::transaction(function () use ($org, $user, $input, $ignoreId) {
            $start = Carbon::parse($input['starts_at']);
            $end = Carbon::parse($input['ends_at']);
            if ($end->lessThanOrEqualTo($start)) {
                throw ValidationException::withMessages([
                    'ends_at' => ['The lesson must end after it starts.'],
                ]);
            }

            $student = DrivingStudent::query()
                ->where('organization_id', $org->id)
                ->whereKey($input['student_id'])
                ->lockForUpdate()
                ->firstOrFail();
            if ($student->status !== 'active') {
                throw ValidationException::withMessages([
                    'student_id' => ['This student is not active.'],
                ]);
            }

            $instructor = DrivingInstructor::query()
                ->where('organization_id', $org->id)
                ->whereKey($input['instructor_id'])
                ->lockForUpdate()
                ->firstOrFail();
            if ($instructor->status !== 'active') {
                throw ValidationException::withMessages([
                    'instructor_id' => ['This instructor is not available.'],
                ]);
            }

            $vehicleId = $input['vehicle_id'] ?? null;
            if (($input['lesson_type'] ?? '') === 'practical' && ! $vehicleId) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['A practical lesson needs a vehicle.'],
                ]);
            }
            if ($vehicleId) {
                $vehicle = DrivingVehicle::query()
                    ->where('organization_id', $org->id)
                    ->whereKey($vehicleId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (! $vehicle->bookable() && ($input['status'] ?? 'scheduled') !== 'cancelled') {
                    throw ValidationException::withMessages([
                        'vehicle_id' => ['This vehicle is under maintenance or otherwise unavailable.'],
                    ]);
                }
                if ($student->branch_id && $vehicle->branch_id && (int) $student->branch_id !== (int) $vehicle->branch_id) {
                    throw ValidationException::withMessages([
                        'vehicle_id' => ['The vehicle belongs to a different branch from the student.'],
                    ]);
                }
            }

            $clash = DrivingLesson::query()
                ->where('organization_id', $org->id)
                ->whereNotIn('status', ['cancelled'])
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->where(function ($query) use ($student, $instructor, $vehicleId) {
                    $query->where('student_id', $student->id)
                        ->orWhere('instructor_id', $instructor->id);
                    if ($vehicleId) {
                        $query->orWhere('vehicle_id', $vehicleId);
                    }
                })
                ->lockForUpdate()
                ->exists();
            if ($clash) {
                throw ValidationException::withMessages([
                    'starts_at' => ['That time overlaps another lesson for this student, instructor, or vehicle.'],
                ]);
            }

            $payload = [
                'organization_id' => $org->id,
                'branch_id' => $student->branch_id,
                'student_id' => $student->id,
                'enrolment_id' => $input['enrolment_id'] ?? null,
                'instructor_id' => $instructor->id,
                'vehicle_id' => $vehicleId,
                'lesson_type' => $input['lesson_type'],
                'starts_at' => $start,
                'ends_at' => $end,
                'location' => $input['location'] ?? null,
                'notes' => $input['notes'] ?? null,
                'status' => $input['status'] ?? 'scheduled',
            ];

            if ($ignoreId) {
                $lesson = DrivingLesson::query()->where('organization_id', $org->id)->whereKey($ignoreId)->firstOrFail();
                $old = $lesson->getAttributes();
                $lesson->update($payload);
                $this->audit->logModel($user, 'update', $lesson, $old);
            } else {
                $lesson = DrivingLesson::query()->create($payload);
                $this->audit->logModel($user, 'create', $lesson);
            }

            $this->queueNotice($org->id, $student->id, 'lesson_confirmation', sprintf(
                'Lesson %s on %s with %s.',
                $lesson->lesson_type,
                $start->format('Y-m-d H:i'),
                $instructor->full_name,
            ));

            return $lesson->fresh(['student', 'instructor', 'vehicle']);
        });
    }

    public function completeLesson(DrivingLesson $lesson, User $user, array $input): DrivingLesson
    {
        return DB::transaction(function () use ($lesson, $user, $input) {
            $locked = DrivingLesson::query()->whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            $old = $locked->getAttributes();
            $status = $input['status'];
            $locked->update([
                'status' => $status,
                'attendance' => $input['attendance'] ?? $locked->attendance,
                'outcome' => $input['outcome'] ?? $locked->outcome,
                'feedback' => $input['feedback'] ?? $locked->feedback,
            ]);
            if ($status === 'completed' && ($old['status'] ?? '') !== 'completed' && $locked->enrolment_id) {
                DrivingEnrolment::query()->whereKey($locked->enrolment_id)->increment('lessons_completed');
            }
            if (in_array($status, ['cancelled', 'no_show'], true)) {
                $this->queueNotice($locked->organization_id, $locked->student_id, 'lesson_'.$status, 'Your lesson was marked '.$status.'.');
            }
            $this->audit->logModel($user, 'update', $locked, $old);

            return $locked->fresh(['student', 'instructor', 'vehicle']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function enrol(Organization $org, User $user, array $input): DrivingEnrolment
    {
        return DB::transaction(function () use ($org, $user, $input) {
            $student = DrivingStudent::query()->where('organization_id', $org->id)->whereKey($input['student_id'])->firstOrFail();
            $course = DrivingCourse::query()->where('organization_id', $org->id)->whereKey($input['course_id'])->where('is_active', true)->firstOrFail();
            if ($course->branch_id && $student->branch_id && (int) $course->branch_id !== (int) $student->branch_id) {
                throw ValidationException::withMessages([
                    'course_id' => ['This course is not offered at the student’s branch.'],
                ]);
            }
            $package = null;
            if (! empty($input['package_id'])) {
                $package = DrivingCoursePackage::query()
                    ->where('organization_id', $org->id)
                    ->where('course_id', $course->id)
                    ->whereKey($input['package_id'])
                    ->where('is_active', true)
                    ->first();
                if (! $package) {
                    throw ValidationException::withMessages([
                        'package_id' => ['Choose a package that belongs to this course.'],
                    ]);
                }
            }
            $price = $package ? $package->price : $course->price;
            $registrationFee = $package ? $package->registration_fee : $course->registration_fee;
            $lessonsRequired = $package
                ? (int) $package->theory_lessons + (int) $package->practical_lessons
                : (int) $course->theory_lessons + (int) $course->practical_lessons;
            $enrolment = DrivingEnrolment::query()->create([
                'organization_id' => $org->id,
                'branch_id' => $student->branch_id,
                'student_id' => $student->id,
                'course_id' => $course->id,
                'package_id' => $package?->id,
                'status' => 'active',
                'enrolled_on' => $input['enrolled_on'] ?? now()->toDateString(),
                'price' => $price,
                'registration_fee' => $registrationFee,
                'lessons_required' => $lessonsRequired,
                'lessons_completed' => 0,
            ]);
            $this->audit->logModel($user, 'create', $enrolment);
            $offer = $package ? $course->name.' · '.$package->name : $course->name;
            if ((float) $price > 0) {
                $this->createInvoice($org, $user, [
                    'student_id' => $student->id,
                    'enrolment_id' => $enrolment->id,
                    'branch_id' => $student->branch_id,
                    'kind' => 'course',
                    'amount' => $price,
                    'discount' => 0,
                    'issued_on' => $enrolment->enrolled_on->toDateString(),
                    'notes' => $offer,
                ]);
            }
            if ((float) $registrationFee > 0) {
                $this->createInvoice($org, $user, [
                    'student_id' => $student->id,
                    'enrolment_id' => $enrolment->id,
                    'branch_id' => $student->branch_id,
                    'kind' => 'registration',
                    'amount' => $registrationFee,
                    'discount' => 0,
                    'issued_on' => $enrolment->enrolled_on->toDateString(),
                    'notes' => 'Registration fee',
                ]);
            }

            return $enrolment->load(['course', 'package']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createInvoice(Organization $org, User $user, array $input): DrivingInvoice
    {
        $discount = round((float) ($input['discount'] ?? 0), 2);
        if ($discount > 0 && ! $user->is_admin && ! $user->is_super_admin) {
            throw ValidationException::withMessages([
                'discount' => ['Only an administrator can apply a discount.'],
            ]);
        }
        $amount = round((float) $input['amount'], 2);
        if ($discount > $amount) {
            throw ValidationException::withMessages([
                'discount' => ['The discount cannot be larger than the invoice amount.'],
            ]);
        }
        $invoice = DrivingInvoice::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $input['branch_id'] ?? null,
            'student_id' => $input['student_id'],
            'enrolment_id' => $input['enrolment_id'] ?? null,
            'invoice_number' => $this->nextNumber(DrivingInvoice::class, $org->id, 'invoice_number', 'INV-'),
            'kind' => $input['kind'] ?? 'course',
            'amount' => $amount,
            'discount' => $discount,
            'status' => 'open',
            'issued_on' => $input['issued_on'] ?? now()->toDateString(),
            'notes' => $input['notes'] ?? null,
        ]);
        $this->audit->logModel($user, 'create', $invoice);

        return $invoice;
    }

    /**
     * Staff-recorded receipt. M-Pesa is not marked provider-confirmed here.
     *
     * @param  array<string, mixed>  $input
     */
    public function takePayment(Organization $org, User $user, array $input): DrivingPayment
    {
        return DB::transaction(function () use ($org, $user, $input) {
            $invoice = DrivingInvoice::query()
                ->where('organization_id', $org->id)
                ->whereKey($input['invoice_id'])
                ->lockForUpdate()
                ->firstOrFail();
            if ($invoice->status === 'void') {
                throw ValidationException::withMessages([
                    'invoice_id' => ['This invoice is void.'],
                ]);
            }
            $reference = trim((string) ($input['reference'] ?? ''));
            if ($reference !== '') {
                $duplicate = DrivingPayment::query()
                    ->where('invoice_id', $invoice->id)
                    ->where('reference', $reference)
                    ->lockForUpdate()
                    ->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'reference' => ['This payment reference is already recorded on the invoice.'],
                    ]);
                }
            }
            $amount = round((float) $input['amount'], 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Enter an amount greater than zero.'],
                ]);
            }
            $outstanding = $this->invoiceOutstanding($invoice);
            if ($amount - $outstanding > 0.009) {
                throw ValidationException::withMessages([
                    'amount' => ['The amount is more than the outstanding balance of KSh '.number_format($outstanding, 2).'.'],
                ]);
            }
            $payment = DrivingPayment::query()->create([
                'organization_id' => $org->id,
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'amount' => $amount,
                'method' => $input['method'],
                'reference' => $reference !== '' ? $reference : null,
                'received_on' => $input['received_on'] ?? now()->toDateString(),
                'received_by' => $user->id,
                'provider_confirmed' => false,
            ]);
            $this->refreshInvoiceStatus($invoice);
            $this->audit->logModel($user, 'create', $payment);
            $this->queueNotice($org->id, $invoice->student_id, 'payment_receipt', sprintf(
                'Receipt %s for KSh %s. This is a staff-recorded receipt.',
                $payment->id,
                number_format($amount, 2),
            ));

            return $payment;
        });
    }

    public function refund(Organization $org, User $user, DrivingPayment $payment, float $amount, string $reason): DrivingRefund
    {
        if (! $user->is_admin && ! $user->is_super_admin) {
            throw ValidationException::withMessages([
                'amount' => ['Only an administrator can authorise a refund.'],
            ]);
        }

        return DB::transaction(function () use ($org, $user, $payment, $amount, $reason) {
            $locked = DrivingPayment::query()->where('organization_id', $org->id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $already = (float) $locked->refunds()->sum('amount');
            $left = round((float) $locked->amount - $already, 2);
            if ($amount <= 0 || $amount - $left > 0.009) {
                throw ValidationException::withMessages([
                    'amount' => ['The refund cannot be more than the KSh '.number_format($left, 2).' still on this receipt.'],
                ]);
            }
            $refund = DrivingRefund::query()->create([
                'organization_id' => $org->id,
                'payment_id' => $locked->id,
                'amount' => round($amount, 2),
                'reason' => $reason,
                'authorized_by' => $user->id,
                'refunded_on' => now()->toDateString(),
            ]);
            $invoice = DrivingInvoice::query()->whereKey($locked->invoice_id)->lockForUpdate()->first();
            if ($invoice) {
                $this->refreshInvoiceStatus($invoice);
            }
            $this->audit->logModel($user, 'create', $refund);

            return $refund;
        });
    }

    public function invoiceOutstanding(DrivingInvoice $invoice): float
    {
        $paid = (float) DrivingPayment::query()->where('invoice_id', $invoice->id)->sum('amount');
        $refunded = (float) DrivingRefund::query()
            ->whereIn('payment_id', DrivingPayment::query()->where('invoice_id', $invoice->id)->select('id'))
            ->sum('amount');

        return max(0, DrivingScheduleRules::outstanding($invoice->netAmount(), $paid, $refunded));
    }

    public function studentBalance(int $studentId): float
    {
        $invoices = DrivingInvoice::query()->where('student_id', $studentId)->where('status', '!=', 'void')->get();
        $total = 0.0;
        foreach ($invoices as $invoice) {
            $total += $this->invoiceOutstanding($invoice);
        }

        return round($total, 2);
    }

    protected function refreshInvoiceStatus(DrivingInvoice $invoice): void
    {
        $left = $this->invoiceOutstanding($invoice->fresh());
        $invoice->update(['status' => $left <= 0.009 ? 'paid' : 'open']);
    }

    public function queueNotice(int $organizationId, ?int $studentId, string $template, string $body): DrivingNotification
    {
        return DrivingNotification::query()->create([
            'organization_id' => $organizationId,
            'student_id' => $studentId,
            'template' => $template,
            'channel' => 'sms',
            'status' => 'queued',
            'body' => $body,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $user, Request $request, int $organizationId): array
    {
        $from = Carbon::parse($request->input('from', now()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();
        $students = $this->scoped(DrivingStudent::query(), $user, $request);
        $lessons = $this->scoped(DrivingLesson::query(), $user, $request);
        $payments = DrivingPayment::query()->where('organization_id', $organizationId);

        $todayLessons = (clone $lessons)->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()]);
        $revenue = (float) (clone $payments)->whereDate('received_on', now()->toDateString())->sum('amount');
        $refunds = (float) DrivingRefund::query()->where('organization_id', $organizationId)->whereDate('refunded_on', now()->toDateString())->sum('amount');

        $balance = 0.0;
        DrivingInvoice::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'open')
            ->orderBy('id')
            ->each(function (DrivingInvoice $invoice) use (&$balance) {
                $balance += $this->invoiceOutstanding($invoice);
            });

        return [
            'active_students' => (clone $students)->where('status', 'active')->count(),
            'new_students' => (clone $students)->whereBetween('registered_on', [$from->toDateString(), $to->toDateString()])->count(),
            'lessons_today' => (clone $todayLessons)->whereNotIn('status', ['cancelled'])->count(),
            'lessons_completed' => (clone $lessons)->where('status', 'completed')->whereBetween('starts_at', [$from, $to])->count(),
            'instructors_available' => $this->scoped(DrivingInstructor::query(), $user, $request)->where('status', 'active')->count(),
            'vehicles_available' => $this->scoped(DrivingVehicle::query(), $user, $request)->where('status', 'available')->count(),
            'revenue_collected_today' => round($revenue - $refunds, 2),
            'outstanding_balances' => round($balance, 2),
            'upcoming_lessons' => (clone $lessons)->with(['student:id,full_name', 'instructor:id,full_name'])
                ->where('starts_at', '>=', now())
                ->whereNotIn('status', ['cancelled', 'completed', 'no_show'])
                ->orderBy('starts_at')
                ->limit(8)
                ->get(),
            'upcoming_assessments' => DrivingAssessment::query()
                ->where('organization_id', $organizationId)
                ->whereDate('assessed_on', '>=', now()->toDateString())
                ->orderBy('assessed_on')
                ->limit(8)
                ->get(),
            'recent_payments' => DrivingPayment::query()
                ->where('organization_id', $organizationId)
                ->latest('id')
                ->limit(8)
                ->get(),
            'revenue_trend' => $this->revenueTrend($organizationId),
            'enrolment_trend' => $this->scoped(DrivingEnrolment::query(), $user, $request)
                ->where('enrolled_on', '>=', now()->subDays(30)->toDateString())
                ->selectRaw('enrolled_on as day, count(*) as total')
                ->groupBy('enrolled_on')
                ->orderBy('enrolled_on')
                ->get(),
        ];
    }

    /**
     * @return list<array{day: string, collected: float}>
     */
    protected function revenueTrend(int $organizationId): array
    {
        $rows = DrivingPayment::query()
            ->where('organization_id', $organizationId)
            ->where('received_on', '>=', now()->subDays(14)->toDateString())
            ->selectRaw('received_on as day, sum(amount) as collected')
            ->groupBy('received_on')
            ->orderBy('received_on')
            ->get();

        return $rows->map(fn ($row) => [
            'day' => (string) $row->day,
            'collected' => round((float) $row->collected, 2),
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function openPackage(DrivingCourse $course, array $input): DrivingCoursePackage
    {
        return DrivingCoursePackage::query()->create([
            'organization_id' => $course->organization_id,
            'course_id' => $course->id,
            'name' => $input['name'],
            'price' => $input['price'],
            'registration_fee' => $input['registration_fee'] ?? 0,
            'practical_lessons' => $input['practical_lessons'] ?? 0,
            'theory_lessons' => $input['theory_lessons'] ?? 0,
            'is_active' => true,
        ]);
    }

    /**
     * Record a student arriving or leaving. A signature or thumb mark is optional.
     *
     * @param  array<string, mixed>  $input
     */
    public function recordAttendance(Organization $org, User $user, array $input): DrivingAttendanceLog
    {
        return DB::transaction(function () use ($org, $user, $input) {
            $student = DrivingStudent::query()
                ->where('organization_id', $org->id)
                ->whereKey($input['student_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $when = isset($input['recorded_at']) ? Carbon::parse($input['recorded_at']) : now();
            $direction = $input['direction'];
            $last = DrivingAttendanceLog::query()
                ->where('organization_id', $org->id)
                ->where('student_id', $student->id)
                ->whereDate('recorded_at', $when->toDateString())
                ->latest('recorded_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($direction === 'in' && $last && $last->direction === 'in') {
                throw ValidationException::withMessages([
                    'direction' => ['This student is already checked in. Check them out when they leave.'],
                ]);
            }
            if ($direction === 'out' && (! $last || $last->direction !== 'in')) {
                throw ValidationException::withMessages([
                    'direction' => ['Check this student in before checking them out.'],
                ]);
            }
            $verification = $input['verification'] ?? 'none';
            $log = DrivingAttendanceLog::query()->create([
                'organization_id' => $org->id,
                'branch_id' => $student->branch_id,
                'student_id' => $student->id,
                'direction' => $direction,
                'recorded_at' => $when->toDateTimeString(),
                'verification' => $verification,
                'mark' => $this->attendanceMark($verification, $input['mark'] ?? null),
                'recorded_by' => $user->id,
            ]);
            $this->audit->logModel($user, 'create', $log, null, [
                'student_id' => $student->id,
                'direction' => $direction,
                'verification' => $verification,
                'recorded_at' => $when->toDateTimeString(),
            ]);

            return $log->load('student:id,full_name,student_number');
        });
    }

    protected function attendanceMark(string $verification, mixed $mark): ?string
    {
        if ($verification === 'none') {
            return null;
        }
        $mark = trim((string) $mark);
        if ($mark === '' || ! preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $mark)) {
            throw ValidationException::withMessages([
                'mark' => ['Draw a signature or thumb mark before saving this attendance.'],
            ]);
        }

        return $mark;
    }

    public function maskDocument(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }
        $tail = substr($number, -4);

        return str_repeat('•', max(0, strlen($number) - 4)).$tail;
    }
}
