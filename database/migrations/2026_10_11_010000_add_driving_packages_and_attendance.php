<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driving_course_packages', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreignId('course_id')->constrained('driving_courses')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('registration_fee', 14, 2)->default(0);
            $table->unsignedSmallInteger('theory_lessons')->default(0);
            $table->unsignedSmallInteger('practical_lessons')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['course_id', 'name']);
            $table->index(['organization_id', 'course_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        $now = now();
        foreach (DB::table('driving_courses')->get() as $course) {
            DB::table('driving_course_packages')->insert([
                'organization_id' => $course->organization_id,
                'course_id' => $course->id,
                'name' => 'Standard',
                'price' => $course->price,
                'registration_fee' => $course->registration_fee,
                'theory_lessons' => $course->theory_lessons,
                'practical_lessons' => $course->practical_lessons,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('driving_enrolments', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->constrained('driving_course_packages')->nullOnDelete();
        });

        Schema::create('driving_attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->foreignId('student_id')->constrained('driving_students')->cascadeOnDelete();
            $table->string('direction', 10);
            $table->dateTime('recorded_at');
            $table->string('verification', 20)->default('none');
            $table->longText('mark')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'student_id', 'recorded_at'], 'drv_attendance_student_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driving_attendance_logs');
        Schema::table('driving_enrolments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });
        Schema::dropIfExists('driving_course_packages');
    }
};
