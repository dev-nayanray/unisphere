<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Department;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;
    private Department $dept;
    private Program $program;
    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrar = User::factory()->create(['role' => 'registrar']);
        $this->dept = Department::create(['name' => 'Computer Science', 'code' => 'CSE']);
        $this->program = Program::create(['department_id' => $this->dept->id, 'name' => 'BSc CSE', 'code' => 'BSCSE']);
        $this->semester = Semester::create(['name' => 'Fall 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'is_current' => true]);
    }

    public function test_lists_paginate_search_and_sort(): void
    {
        Course::create(['program_id' => $this->program->id, 'code' => 'CSE 201', 'title' => 'Data Structures', 'credit' => 3]);
        Course::create(['program_id' => $this->program->id, 'code' => 'CSE 101', 'title' => 'Programming', 'credit' => 3]);
        Course::create(['program_id' => $this->program->id, 'code' => 'MAT 101', 'title' => 'Calculus', 'credit' => 3]);

        $this->actingAs($this->registrar)
            ->getJson('/api/admin/courses?search=CSE&sort=code&dir=desc&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.code', 'CSE 201');

        // Without page/per_page a plain array comes back (used by dropdowns).
        $this->actingAs($this->registrar)->getJson('/api/admin/courses')->assertOk()->assertJsonCount(3);
    }

    public function test_department_can_be_edited_and_head_must_be_faculty(): void
    {
        $teacher = User::factory()->teacher()->create();
        $student = User::factory()->create();

        $this->actingAs($this->registrar)
            ->putJson("/api/admin/departments/{$this->dept->id}", ['name' => 'CSE Dept', 'head_id' => $teacher->id])
            ->assertOk()
            ->assertJsonPath('head.id', $teacher->id);

        $this->actingAs($this->registrar)
            ->putJson("/api/admin/departments/{$this->dept->id}", ['head_id' => $student->id])
            ->assertJsonValidationErrors('head_id');
    }

    public function test_records_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->registrar)
            ->deleteJson("/api/admin/departments/{$this->dept->id}")
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'CSE still has 1 program(s) and 0 faculty member(s). Move or delete them first.']);

        $course = Course::create(['program_id' => $this->program->id, 'code' => 'CSE 101', 'title' => 'Intro', 'credit' => 3]);
        $section = CourseSection::create(['course_id' => $course->id, 'semester_id' => $this->semester->id, 'section' => 'A']);
        Registration::create(['course_section_id' => $section->id, 'student_id' => User::factory()->create()->id, 'status' => 'approved']);

        $this->actingAs($this->registrar)->deleteJson("/api/admin/courses/{$course->id}")->assertUnprocessable();
        $this->actingAs($this->registrar)->deleteJson("/api/admin/sections/{$section->id}")->assertUnprocessable();
        $this->actingAs($this->registrar)->deleteJson("/api/admin/semesters/{$this->semester->id}")->assertUnprocessable();
    }

    public function test_unused_records_can_be_deleted(): void
    {
        $empty = Department::create(['name' => 'Physics', 'code' => 'PHY']);

        $this->actingAs($this->registrar)->deleteJson("/api/admin/departments/{$empty->id}")->assertOk();
        $this->assertModelMissing($empty);
    }

    public function test_make_current_switches_the_current_semester(): void
    {
        $spring = Semester::create(['name' => 'Spring 2027', 'start_date' => '2027-01-10', 'end_date' => '2027-05-10']);

        $this->actingAs($this->registrar)->postJson("/api/admin/semesters/{$spring->id}/make-current")->assertOk();

        $this->assertTrue($spring->fresh()->is_current);
        $this->assertFalse($this->semester->fresh()->is_current);
    }

    public function test_add_drop_deadline_closes_registration(): void
    {
        $this->semester->update(['registration_open' => true, 'registration_deadline' => now()->subDay()->toDateString()]);
        $this->assertFalse($this->semester->fresh()->acceptsRegistration());

        $this->actingAs($this->registrar)
            ->putJson("/api/admin/semesters/{$this->semester->id}", ['registration_deadline' => now()->addWeek()->toDateString()])
            ->assertOk();
        $this->assertTrue($this->semester->fresh()->acceptsRegistration());
    }

    public function test_section_schedule_and_grading_scheme_are_validated(): void
    {
        $course = Course::create(['program_id' => $this->program->id, 'code' => 'CSE 101', 'title' => 'Intro', 'credit' => 3]);
        $base = ['course_id' => $course->id, 'semester_id' => $this->semester->id, 'section' => 'A'];

        $this->actingAs($this->registrar)
            ->postJson('/api/admin/sections', [...$base, 'grading_scheme' => ['attendance' => 10, 'final' => 50]])
            ->assertJsonValidationErrors('grading_scheme');

        $this->actingAs($this->registrar)
            ->postJson('/api/admin/sections', [...$base, 'schedule' => [['day' => 'Sun', 'start' => '10:00', 'end' => '09:00']]])
            ->assertJsonValidationErrors('schedule.0.end');

        $this->actingAs($this->registrar)
            ->postJson('/api/admin/sections', [...$base, 'grading_scheme' => ['attendance' => 10, 'assignment' => 10, 'quiz' => 10, 'mid' => 30, 'final' => 40]])
            ->assertCreated();

        // Same course, semester and label again is refused.
        $this->actingAs($this->registrar)->postJson('/api/admin/sections', $base)->assertJsonValidationErrors('section');
    }

    public function test_reset_password_and_deactivate_user(): void
    {
        $teacher = User::factory()->teacher()->create();
        $teacher->createToken('web');

        $temporary = $this->actingAs($this->registrar)
            ->postJson("/api/admin/users/{$teacher->id}/reset-password")
            ->assertOk()
            ->json('temporary_password');

        $teacher->refresh();
        $this->assertTrue(Hash::check($temporary, $teacher->password));
        $this->assertTrue($teacher->must_change_password);
        $this->assertSame(0, $teacher->tokens()->count());

        $this->actingAs($this->registrar)->putJson("/api/admin/users/{$teacher->id}", ['is_active' => false])->assertOk();
        $this->assertFalse($teacher->fresh()->is_active);
    }

    public function test_assign_advisor_to_student(): void
    {
        $advisor = User::factory()->teacher()->create();
        $student = User::factory()->create();
        $student->student()->create(['program_id' => $this->program->id, 'student_id' => 'S-1', 'batch' => '2022']);

        $this->actingAs($this->registrar)
            ->putJson("/api/admin/users/{$student->id}", ['advisor_id' => $advisor->id])
            ->assertOk()
            ->assertJsonPath('student.advisor.id', $advisor->id);
    }

    public function test_users_with_academic_records_cannot_be_deleted(): void
    {
        $teacher = User::factory()->teacher()->create();
        $course = Course::create(['program_id' => $this->program->id, 'code' => 'CSE 101', 'title' => 'Intro', 'credit' => 3]);
        CourseSection::create(['course_id' => $course->id, 'semester_id' => $this->semester->id, 'section' => 'A', 'teacher_id' => $teacher->id]);

        $this->actingAs($this->registrar)->deleteJson("/api/admin/users/{$teacher->id}")->assertUnprocessable();
    }
}
