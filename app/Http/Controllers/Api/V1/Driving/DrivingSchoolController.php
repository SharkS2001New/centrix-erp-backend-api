<?php

namespace App\Http\Controllers\Api\V1\Driving;

use App\Http\Controllers\Controller;
use App\Models\Driving\DrivingAssessment;
use App\Models\Driving\DrivingCourse;
use App\Models\Driving\DrivingExpense;
use App\Models\Driving\DrivingInstructor;
use App\Models\Driving\DrivingInvoice;
use App\Models\Driving\DrivingLesson;
use App\Models\Driving\DrivingNotification;
use App\Models\Driving\DrivingPayment;
use App\Models\Driving\DrivingStudent;
use App\Models\Driving\DrivingVehicle;
use App\Models\Driving\DrivingVehicleService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Driving\DrivingSchoolService;
use App\Services\Erp\CapabilityGate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DrivingSchoolController extends Controller
{
    public function __construct(
        protected DrivingSchoolService $driving,
        protected AuditLogger $audit,
    ) {}

    public function dashboard(Request $request)
    {
        $org = $this->organization($request);

        return response()->json([
            'data' => $this->driving->dashboard($request->user(), $request, $org->id),
        ]);
    }

    public function students(Request $request)
    {
        $query = $this->driving->scoped(DrivingStudent::query(), $request->user(), $request);
        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('full_name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('student_number', 'like', "%{$q}%");
            });
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        $query->orderByDesc('id');
        if ($request->input('format') === 'csv') {
            return $this->csv('students.csv', ['Number', 'Name', 'Phone', 'Status', 'Registered'], $query->get()->map(fn ($row) => [
                $row->student_number, $row->full_name, $row->phone, $row->status, optional($row->registered_on)->toDateString(),
            ]));
        }
        $page = $query->paginate(min((int) $request->input('per_page', 25), 100));
        $page->getCollection()->transform(function (DrivingStudent $student) {
            $student->id_document_number = $this->driving->maskDocument($student->id_document_number);
            $student->setAttribute('outstanding', $this->driving->studentBalance($student->id));

            return $student;
        });

        return response()->json($page);
    }

    public function showStudent(Request $request, DrivingStudent $student)
    {
        $this->findStudent($request, $student);
        $student->load(['enrolments.course', 'lessons.instructor', 'invoices.payments.refunds']);
        $student->setAttribute('outstanding', $this->driving->studentBalance($student->id));

        return response()->json(['data' => $student]);
    }

    public function storeStudent(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'id_document_type' => ['nullable', 'string', 'max:40'],
            'id_document_number' => ['nullable', 'string', 'max:80'],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_name' => ['nullable', 'string', 'max:160'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer'],
            'preferred_instructor_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'completed', 'suspended'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'registered_on' => ['nullable', 'date'],
        ]);
        $this->driving->assertNoDuplicateStudent($org->id, $data['phone']);
        $student = DrivingStudent::query()->create([
            ...$data,
            'organization_id' => $org->id,
            'branch_id' => $this->driving->branchIdForWrite($request->user(), isset($data['branch_id']) ? (int) $data['branch_id'] : null, $request),
            'student_number' => $this->driving->nextNumber(DrivingStudent::class, $org->id, 'student_number', 'DS-'),
            'registered_on' => $data['registered_on'] ?? now()->toDateString(),
            'status' => $data['status'] ?? 'active',
        ]);
        $this->audit->logModel($request->user(), 'create', $student);
        $this->driving->queueNotice($org->id, $student->id, 'student_registration', 'Student '.$student->student_number.' was registered. The message is queued, not sent.');

        return response()->json(['data' => $student], 201);
    }

    public function updateStudent(Request $request, DrivingStudent $student)
    {
        $this->findStudent($request, $student);
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:160'],
            'phone' => ['sometimes', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'id_document_type' => ['nullable', 'string', 'max:40'],
            'id_document_number' => ['nullable', 'string', 'max:80'],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_name' => ['nullable', 'string', 'max:160'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'preferred_instructor_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'completed', 'suspended'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['phone'])) {
            $this->driving->assertNoDuplicateStudent($student->organization_id, $data['phone'], $student->id);
        }
        $old = $student->getAttributes();
        $student->update($data);
        $this->audit->logModel($request->user(), 'update', $student, $old);

        return response()->json(['data' => $student->fresh()]);
    }

    public function courses(Request $request)
    {
        $query = $this->driving->scoped(DrivingCourse::query(), $request->user(), $request);

        return response()->json($query->orderBy('name')->paginate(100));
    }

    public function storeCourse(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'licence_category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'theory_lessons' => ['nullable', 'integer', 'min:0', 'max:500'],
            'practical_lessons' => ['nullable', 'integer', 'min:0', 'max:500'],
            'lesson_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'price' => ['required', 'numeric', 'min:0'],
            'registration_fee' => ['nullable', 'numeric', 'min:0'],
            'instalment_count' => ['nullable', 'integer', 'min:1', 'max:36'],
            'branch_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $course = DrivingCourse::query()->create([
            ...$data,
            'organization_id' => $org->id,
            'branch_id' => $this->driving->branchIdForWrite($request->user(), isset($data['branch_id']) ? (int) $data['branch_id'] : null, $request),
            'is_active' => $data['is_active'] ?? true,
        ]);
        $this->audit->logModel($request->user(), 'create', $course);

        return response()->json(['data' => $course], 201);
    }

    public function updateCourse(Request $request, DrivingCourse $course)
    {
        $this->owns($request, $course->organization_id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'licence_category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'theory_lessons' => ['nullable', 'integer', 'min:0', 'max:500'],
            'practical_lessons' => ['nullable', 'integer', 'min:0', 'max:500'],
            'lesson_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'registration_fee' => ['nullable', 'numeric', 'min:0'],
            'instalment_count' => ['nullable', 'integer', 'min:1', 'max:36'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $old = $course->getAttributes();
        $course->update($data);
        $this->audit->logModel($request->user(), 'update', $course, $old);

        return response()->json(['data' => $course->fresh()]);
    }

    public function enrol(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'course_id' => ['required', 'integer'],
            'enrolled_on' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->driving->enrol($org, $request->user(), $data)], 201);
    }

    public function instructors(Request $request)
    {
        return response()->json($this->driving->scoped(DrivingInstructor::query(), $request->user(), $request)->orderBy('full_name')->paginate(100));
    }

    public function storeInstructor(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email'],
            'licence_info' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
            'lesson_pay' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'user_id' => ['nullable', 'integer'],
        ]);
        $instructor = DrivingInstructor::query()->create([
            ...$data,
            'organization_id' => $org->id,
            'branch_id' => $this->driving->branchIdForWrite($request->user(), isset($data['branch_id']) ? (int) $data['branch_id'] : null, $request),
            'instructor_number' => $this->driving->nextNumber(DrivingInstructor::class, $org->id, 'instructor_number', 'INS-'),
            'status' => $data['status'] ?? 'active',
            'lesson_pay' => $data['lesson_pay'] ?? 0,
        ]);
        $this->audit->logModel($request->user(), 'create', $instructor);

        return response()->json(['data' => $instructor], 201);
    }

    public function vehicles(Request $request)
    {
        return response()->json($this->driving->scoped(DrivingVehicle::query(), $request->user(), $request)->orderBy('registration_number')->paginate(100));
    }

    public function storeVehicle(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'registration_number' => ['required', 'string', 'max:40'],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1980', 'max:2100'],
            'category' => ['nullable', 'string', 'max:80'],
            'transmission' => ['nullable', Rule::in(['manual', 'automatic'])],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['available', 'in_use', 'maintenance', 'inactive'])],
            'insurance_expires_on' => ['nullable', 'date'],
            'inspection_expires_on' => ['nullable', 'date'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'maintenance_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $vehicle = DrivingVehicle::query()->create([
            ...$data,
            'organization_id' => $org->id,
            'branch_id' => $this->driving->branchIdForWrite($request->user(), isset($data['branch_id']) ? (int) $data['branch_id'] : null, $request),
            'status' => $data['status'] ?? 'available',
            'transmission' => $data['transmission'] ?? 'manual',
        ]);
        $this->audit->logModel($request->user(), 'create', $vehicle);

        return response()->json(['data' => $vehicle], 201);
    }

    public function updateVehicle(Request $request, DrivingVehicle $vehicle)
    {
        $this->owns($request, $vehicle->organization_id);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['available', 'in_use', 'maintenance', 'inactive'])],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'insurance_expires_on' => ['nullable', 'date'],
            'inspection_expires_on' => ['nullable', 'date'],
            'maintenance_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $old = $vehicle->getAttributes();
        $vehicle->update($data);
        $this->audit->logModel($request->user(), 'update', $vehicle, $old);

        return response()->json(['data' => $vehicle->fresh()]);
    }

    public function storeService(Request $request, DrivingVehicle $vehicle)
    {
        $this->owns($request, $vehicle->organization_id);
        $data = $request->validate([
            'serviced_on' => ['required', 'date'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $service = DrivingVehicleService::query()->create([
            ...$data,
            'organization_id' => $vehicle->organization_id,
            'vehicle_id' => $vehicle->id,
            'cost' => $data['cost'] ?? 0,
        ]);
        if (isset($data['odometer'])) {
            $vehicle->update(['odometer' => $data['odometer']]);
        }

        return response()->json(['data' => $service], 201);
    }

    public function lessons(Request $request)
    {
        $query = $this->driving->scoped(DrivingLesson::query()->with(['student:id,full_name', 'instructor:id,full_name', 'vehicle:id,registration_number']), $request->user(), $request);
        if ($from = $request->input('from')) {
            $query->where('starts_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to = $request->input('to')) {
            $query->where('starts_at', '<=', Carbon::parse($to)->endOfDay());
        }
        $instructor = DrivingInstructor::query()->where('user_id', $request->user()->id)->where('organization_id', $this->organization($request)->id)->first();
        if ($instructor && ! $request->user()->is_admin && ! $request->user()->is_super_admin) {
            $query->where('instructor_id', $instructor->id);
        }

        return response()->json($query->orderBy('starts_at')->paginate(100));
    }

    public function storeLesson(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'instructor_id' => ['required', 'integer'],
            'vehicle_id' => ['nullable', 'integer'],
            'enrolment_id' => ['nullable', 'integer'],
            'lesson_type' => ['required', Rule::in(['theory', 'practical'])],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $start = Carbon::parse($data['starts_at']);
        $data['starts_at'] = $start->toDateTimeString();
        $data['ends_at'] = $start->copy()->addMinutes((int) ($data['duration_minutes'] ?? 60))->toDateTimeString();

        return response()->json(['data' => $this->driving->bookLesson($org, $request->user(), $data)], 201);
    }

    public function updateLesson(Request $request, DrivingLesson $lesson)
    {
        $this->owns($request, $lesson->organization_id);
        $data = $request->validate([
            'status' => ['required', Rule::in(['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'])],
            'attendance' => ['nullable', Rule::in(['present', 'absent', 'late', 'excused'])],
            'outcome' => ['nullable', 'string', 'max:40'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->driving->completeLesson($lesson, $request->user(), $data)]);
    }

    public function assessments(Request $request)
    {
        $org = $this->organization($request);

        return response()->json(
            DrivingAssessment::query()->where('organization_id', $org->id)->latest('assessed_on')->paginate(50)
        );
    }

    public function storeAssessment(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'enrolment_id' => ['nullable', 'integer'],
            'instructor_id' => ['nullable', 'integer'],
            'kind' => ['required', Rule::in(['theory', 'practical'])],
            'title' => ['required', 'string', 'max:160'],
            'score' => ['required', 'numeric', 'min:0'],
            'max_score' => ['nullable', 'numeric', 'min:1'],
            'comments' => ['nullable', 'string', 'max:2000'],
            'assessed_on' => ['required', 'date'],
        ]);
        $max = (float) ($data['max_score'] ?? 100);
        $this->findStudent($request, DrivingStudent::query()->where('organization_id', $org->id)->whereKey($data['student_id'])->firstOrFail());
        $assessment = DrivingAssessment::query()->create([
            ...$data,
            'organization_id' => $org->id,
            'max_score' => $max,
            'passed' => (float) $data['score'] >= ($max * 0.5),
            'official_exam' => false,
        ]);
        $this->audit->logModel($request->user(), 'create', $assessment);

        return response()->json([
            'data' => $assessment,
            'message' => 'Saved as a school assessment. This is not an official licensing result.',
        ], 201);
    }

    public function invoices(Request $request)
    {
        $org = $this->organization($request);
        $page = DrivingInvoice::query()->where('organization_id', $org->id)->latest('id')->paginate(50);
        $page->getCollection()->transform(function (DrivingInvoice $invoice) {
            $invoice->setAttribute('outstanding', $this->driving->invoiceOutstanding($invoice));

            return $invoice;
        });

        return response()->json($page);
    }

    public function storeInvoice(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'enrolment_id' => ['nullable', 'integer'],
            'kind' => ['nullable', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'issued_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $student = $this->findStudent($request, DrivingStudent::query()->where('organization_id', $org->id)->whereKey($data['student_id'])->firstOrFail());
        $data['branch_id'] = $student->branch_id;

        return response()->json(['data' => $this->driving->createInvoice($org, $request->user(), $data)], 201);
    }

    public function storePayment(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['cash', 'bank', 'card', 'mpesa'])],
            'reference' => ['nullable', 'string', 'max:80'],
            'received_on' => ['nullable', 'date'],
        ]);
        $payment = $this->driving->takePayment($org, $request->user(), $data);

        return response()->json([
            'data' => $payment,
            'message' => $data['method'] === 'mpesa'
                ? 'Receipt recorded. It is not a Safaricom-confirmed M-Pesa payment until a configured callback matches it.'
                : 'Payment recorded.',
        ], 201);
    }

    public function storeRefund(Request $request, DrivingPayment $payment)
    {
        $org = $this->organization($request);
        $this->owns($request, $payment->organization_id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return response()->json([
            'data' => $this->driving->refund($org, $request->user(), $payment, (float) $data['amount'], $data['reason']),
        ], 201);
    }

    public function expenses(Request $request)
    {
        $query = $this->driving->scoped(DrivingExpense::query(), $request->user(), $request);
        if ($request->input('format') === 'csv') {
            return $this->csv('expenses.csv', ['Date', 'Category', 'Amount', 'Method'], $query->orderByDesc('spent_on')->get()->map(fn ($row) => [
                optional($row->spent_on)->toDateString(), $row->category, $row->amount, $row->method,
            ]));
        }

        return response()->json($query->orderByDesc('spent_on')->paginate(50));
    }

    public function storeExpense(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'min:0'],
            'spent_on' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'supplier' => ['nullable', 'string', 'max:160'],
            'method' => ['nullable', Rule::in(['cash', 'bank', 'card', 'mpesa'])],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $expense = DrivingExpense::query()->create([
            ...$data,
            'organization_id' => $org->id,
            'branch_id' => $this->driving->branchIdForWrite($request->user(), isset($data['branch_id']) ? (int) $data['branch_id'] : null, $request),
            'method' => $data['method'] ?? 'cash',
            'recorded_by' => $request->user()->id,
            'status' => 'recorded',
        ]);
        $this->audit->logModel($request->user(), 'create', $expense);

        return response()->json(['data' => $expense], 201);
    }

    public function reports(Request $request)
    {
        $org = $this->organization($request);
        $report = (string) $request->input('report', 'revenue');
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $rows = match ($report) {
            'enrolments' => $this->driving->scoped(DrivingStudent::query(), $request->user(), $request)
                ->whereBetween('registered_on', [$from, $to])
                ->orderBy('registered_on')
                ->get(['student_number', 'full_name', 'registered_on', 'status']),
            'outstanding' => DrivingInvoice::query()->where('organization_id', $org->id)->where('status', 'open')->get()->map(function (DrivingInvoice $invoice) {
                $invoice->setAttribute('outstanding', $this->driving->invoiceOutstanding($invoice));

                return $invoice;
            }),
            'lessons' => $this->driving->scoped(DrivingLesson::query(), $request->user(), $request)
                ->whereBetween('starts_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
                ->get(['id', 'lesson_type', 'status', 'starts_at', 'attendance']),
            'vehicles' => $this->driving->scoped(DrivingVehicle::query(), $request->user(), $request)->get(),
            'expenses' => $this->driving->scoped(DrivingExpense::query(), $request->user(), $request)->whereBetween('spent_on', [$from, $to])->get(),
            default => DrivingPayment::query()->where('organization_id', $org->id)->whereBetween('received_on', [$from, $to])->orderBy('received_on')->get(),
        };

        return response()->json([
            'report' => $report,
            'from' => $from,
            'to' => $to,
            'note' => 'Collected amounts are receipts, not profit. Expenses are listed separately.',
            'data' => $rows,
        ]);
    }

    public function notifications(Request $request)
    {
        $org = $this->organization($request);

        return response()->json(
            DrivingNotification::query()->where('organization_id', $org->id)->latest('id')->paginate(50)
        );
    }

    public function settings(Request $request)
    {
        $org = $this->organization($request);
        $settings = is_array($org->module_settings) ? ($org->module_settings['driving'] ?? []) : [];

        return response()->json(['data' => $settings]);
    }

    public function updateSettings(Request $request)
    {
        $org = $this->organization($request);
        $data = $request->validate([
            'school_name' => ['nullable', 'string', 'max:160'],
            'reminder_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'sms_enabled' => ['nullable', 'boolean'],
        ]);
        $org->putModuleSettingsSection('driving', $data);
        $this->audit->log($request->user(), 'update', 'organizations', $org->id, null, ['driving' => $data], $request);

        return response()->json([
            'data' => $data,
            'message' => 'Saved. SMS stays queued until a messaging provider is connected.',
        ]);
    }

    protected function organization(Request $request): Organization
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('organization');
        $org = $user->organization;
        abort_unless($org, 403, 'No organisation is signed in.');
        $enabled = app(CapabilityGate::class)->forOrganization($org)->enabled('driving');
        abort_unless($enabled, 403, 'Driving School is not enabled for this organisation.');

        return $org;
    }

    protected function owns(Request $request, int $organizationId): void
    {
        abort_unless((int) $this->organization($request)->id === $organizationId, 404);
    }

    protected function findStudent(Request $request, DrivingStudent $student): DrivingStudent
    {
        $this->owns($request, (int) $student->organization_id);

        return $student;
    }

    protected function csv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, is_array($row) ? $row : (array) $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
