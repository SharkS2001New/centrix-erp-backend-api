<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driving_courses', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->string('code', 40);
            $table->string('name');
            $table->string('licence_category', 80)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('theory_lessons')->default(0);
            $table->unsignedSmallInteger('practical_lessons')->default(0);
            $table->unsignedSmallInteger('lesson_minutes')->default(60);
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('registration_fee', 14, 2)->default(0);
            $table->unsignedSmallInteger('instalment_count')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_instructors', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('instructor_number', 40);
            $table->string('full_name');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('licence_info')->nullable();
            $table->json('working_hours')->nullable();
            $table->decimal('lesson_pay', 14, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'instructor_number']);
            $table->index(['organization_id', 'branch_id', 'status']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_students', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->string('student_number', 40);
            $table->string('full_name');
            $table->string('phone', 40);
            $table->string('email')->nullable();
            $table->string('id_document_type', 40)->nullable();
            $table->string('id_document_number', 80)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_name')->nullable();
            $table->string('emergency_phone', 40)->nullable();
            $table->date('registered_on');
            $table->foreignId('preferred_instructor_id')->nullable()->constrained('driving_instructors')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'student_number']);
            $table->index(['organization_id', 'phone']);
            $table->index(['organization_id', 'branch_id', 'status']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_enrolments', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->foreignId('student_id')->constrained('driving_students')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('driving_courses')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->date('enrolled_on');
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('registration_fee', 14, 2)->default(0);
            $table->unsignedSmallInteger('lessons_required')->default(0);
            $table->unsignedSmallInteger('lessons_completed')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'student_id', 'status']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_vehicles', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->string('registration_number', 40);
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('category', 80)->nullable();
            $table->string('transmission', 20)->default('manual');
            $table->string('status', 20)->default('available');
            $table->date('insurance_expires_on')->nullable();
            $table->date('inspection_expires_on')->nullable();
            $table->integer('odometer')->default(0);
            $table->text('maintenance_notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'registration_number']);
            $table->index(['organization_id', 'branch_id', 'status']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_vehicle_services', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreignId('vehicle_id')->constrained('driving_vehicles')->cascadeOnDelete();
            $table->date('serviced_on');
            $table->integer('odometer')->nullable();
            $table->decimal('cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'vehicle_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_lessons', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->foreignId('student_id')->constrained('driving_students')->cascadeOnDelete();
            $table->foreignId('enrolment_id')->nullable()->constrained('driving_enrolments')->nullOnDelete();
            $table->foreignId('instructor_id')->constrained('driving_instructors')->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('driving_vehicles')->nullOnDelete();
            $table->string('lesson_type', 20);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->string('outcome', 40)->nullable();
            $table->text('feedback')->nullable();
            $table->string('attendance', 20)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->timestamps();
            $table->index(['organization_id', 'starts_at']);
            $table->index(['instructor_id', 'starts_at']);
            $table->index(['vehicle_id', 'starts_at']);
            $table->index(['student_id', 'starts_at']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_assessments', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreignId('student_id')->constrained('driving_students')->cascadeOnDelete();
            $table->foreignId('enrolment_id')->nullable()->constrained('driving_enrolments')->nullOnDelete();
            $table->foreignId('instructor_id')->nullable()->constrained('driving_instructors')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('title');
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('max_score', 8, 2)->default(100);
            $table->boolean('passed')->default(false);
            $table->text('comments')->nullable();
            $table->date('assessed_on');
            $table->boolean('official_exam')->default(false);
            $table->timestamps();
            $table->index(['organization_id', 'student_id', 'assessed_on']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_invoices', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->foreignId('student_id')->constrained('driving_students')->cascadeOnDelete();
            $table->foreignId('enrolment_id')->nullable()->constrained('driving_enrolments')->nullOnDelete();
            $table->string('invoice_number', 40);
            $table->string('kind', 30)->default('course');
            $table->decimal('amount', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->string('status', 20)->default('open');
            $table->date('issued_on');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'invoice_number']);
            $table->index(['organization_id', 'student_id', 'status']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_payments', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreignId('invoice_id')->constrained('driving_invoices')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('driving_students')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 20);
            $table->string('reference', 80)->nullable();
            $table->date('received_on');
            $table->unsignedBigInteger('received_by')->nullable();
            $table->boolean('provider_confirmed')->default(false);
            $table->timestamps();
            $table->index(['organization_id', 'received_on']);
            $table->index(['invoice_id', 'reference']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_refunds', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreignId('payment_id')->constrained('driving_payments')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->date('refunded_on');
            $table->timestamps();
            $table->index(['organization_id', 'payment_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_expenses', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->string('category', 80);
            $table->decimal('amount', 14, 2);
            $table->date('spent_on');
            $table->string('supplier')->nullable();
            $table->string('method', 20)->default('cash');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'spent_on']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('driving_notifications', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreignId('student_id')->nullable()->constrained('driving_students')->nullOnDelete();
            $table->string('template', 60);
            $table->string('channel', 20)->default('sms');
            $table->string('status', 20)->default('queued');
            $table->text('body');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driving_notifications');
        Schema::dropIfExists('driving_expenses');
        Schema::dropIfExists('driving_refunds');
        Schema::dropIfExists('driving_payments');
        Schema::dropIfExists('driving_invoices');
        Schema::dropIfExists('driving_assessments');
        Schema::dropIfExists('driving_lessons');
        Schema::dropIfExists('driving_vehicle_services');
        Schema::dropIfExists('driving_vehicles');
        Schema::dropIfExists('driving_enrolments');
        Schema::dropIfExists('driving_students');
        Schema::dropIfExists('driving_instructors');
        Schema::dropIfExists('driving_courses');
    }
};
