<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->foreignId('head_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->unsignedSmallInteger('total_credits')->default(160);
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('advisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('student_id', 30)->unique();
            $table->string('batch', 20);
            $table->date('admission_date')->nullable();
            $table->decimal('cgpa', 3, 2)->default(0);
            $table->string('status', 20)->default('active'); // active, suspended, graduated
            $table->timestamps();
        });

        Schema::create('faculty_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('employee_id', 30)->unique();
            $table->string('designation', 60);
            $table->timestamps();
        });

        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40); // e.g. "Fall 2026"
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('registration_open')->default(false);
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('code', 20)->unique();
            $table->string('title');
            $table->decimal('credit', 3, 1);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // A course section in a semester is also a virtual classroom.
        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('section', 10);
            $table->string('room', 30)->nullable();
            $table->unsignedSmallInteger('capacity')->default(40);
            $table->string('join_code', 8)->unique();
            $table->json('schedule')->nullable(); // [{day: "Sun", start: "09:00", end: "10:30"}]
            $table->json('grading_scheme')->nullable(); // overrides config('unisphere.default_grading_scheme')
            $table->string('cover_color', 20)->default('indigo');
            $table->timestamps();
            $table->unique(['course_id', 'semester_id', 'section']);
        });

        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, dropped
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['course_section_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('semesters');
        Schema::dropIfExists('faculty_members');
        Schema::dropIfExists('students');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('departments');
    }
};
