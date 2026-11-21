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
use Tests\TestCase;

class ClassroomTest extends TestCase
{
    use RefreshDatabase;

    private CourseSection $section;
    private User $teacher;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'CSE', 'code' => 'CSE']);
        $program = Program::create(['department_id' => $dept->id, 'name' => 'BSc CSE', 'code' => 'BSCSE']);
        $course = Course::create(['program_id' => $program->id, 'code' => 'CSE 101', 'title' => 'Intro', 'credit' => 3]);
        $semester = Semester::create(['name' => 'Fall', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        $this->teacher = User::factory()->teacher()->create();
        $this->student = User::factory()->create();

        $this->section = CourseSection::create([
            'course_id' => $course->id, 'semester_id' => $semester->id,
            'teacher_id' => $this->teacher->id, 'section' => 'A',
        ]);

        Registration::create([
            'course_section_id' => $this->section->id,
            'student_id' => $this->student->id,
            'status' => Registration::APPROVED,
        ]);
    }

    public function test_each_section_gets_a_join_code(): void
    {
        $this->assertSame(7, strlen($this->section->join_code));
    }

    public function test_outsiders_cannot_open_a_class(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson("/api/classes/{$this->section->id}")
            ->assertForbidden();
    }

    public function test_assignment_grade_flows_into_the_gradebook(): void
    {
        $assignmentId = $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->section->id}/assignments", [
                'title' => 'Lab report', 'category' => 'assignment', 'max_marks' => 20,
            ])
            ->assertCreated()
            ->json('id');

        $submissionId = $this->actingAs($this->student)
            ->postJson("/api/assignments/{$assignmentId}/submit", ['content' => 'My answer'])
            ->assertCreated()
            ->json('id');

        $this->actingAs($this->teacher)
            ->patchJson("/api/submissions/{$submissionId}/grade", ['marks' => 20])
            ->assertOk();

        // 20/20 in the "assignment" category is worth its full weight (10 by default).
        $response = $this->actingAs($this->teacher)
            ->getJson("/api/classes/{$this->section->id}/gradebook")
            ->assertOk();

        // JSON drops the ".0" from whole floats, so compare loosely.
        $this->assertEquals(10, $response->json('rows.0.breakdown.assignment'));
        $this->assertSame('F', $response->json('rows.0.letter_grade')); // only 10/100 so far
    }
}
