<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Department;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradebookEditorTest extends TestCase
{
    use RefreshDatabase;

    private CourseSection $section;
    private User $teacher;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'CSE', 'code' => 'CSE']);
        $program = Program::create(['department_id' => $dept->id, 'name' => 'BSc', 'code' => 'BSC']);
        $course = Course::create(['program_id' => $program->id, 'code' => 'CSE 101', 'title' => 'Intro', 'credit' => 3]);
        $semester = Semester::create(['name' => 'Fall', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        $this->teacher = User::factory()->teacher()->create();
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $this->section = CourseSection::create([
            'course_id' => $course->id, 'semester_id' => $semester->id, 'teacher_id' => $this->teacher->id, 'section' => 'A',
            'grading_scheme' => ['assignment' => 40, 'quiz' => 60],
        ]);
        foreach ([$this->alice, $this->bob] as $s) {
            Registration::create(['course_section_id' => $this->section->id, 'student_id' => $s->id, 'status' => 'approved']);
        }
    }

    private function assignment(array $extra = []): Assignment
    {
        return Assignment::create([
            'course_section_id' => $this->section->id, 'author_id' => $this->teacher->id, 'title' => 'Lab',
            'category' => 'assignment', 'max_marks' => 20, 'published_at' => now(), 'assign_to' => 'all', 'late_policy' => 'accept', ...$extra,
        ]);
    }

    private function cell(array $body)
    {
        return $this->actingAs($this->teacher)->patchJson("/api/classes/{$this->section->id}/gradebook/cells", $body);
    }

    private function row(User $student): array
    {
        return collect($this->actingAs($this->teacher)->getJson("/api/classes/{$this->section->id}/gradebook")->assertOk()->json('rows'))
            ->firstWhere('student.id', $student->id);
    }

    public function test_grid_has_a_column_per_item_and_cells_can_be_edited(): void
    {
        $a = $this->assignment();
        $this->assignment(['title' => 'Draft', 'published_at' => null]); // drafts are not columns

        $grid = $this->actingAs($this->teacher)->getJson("/api/classes/{$this->section->id}/gradebook")->assertOk();
        $this->assertSame(["assignment:{$a->id}"], collect($grid->json('columns'))->pluck('key')->all());
        $this->assertSame('missing', $grid->json('rows.0.cells')["assignment:{$a->id}"]['status']);

        // Paper work: the teacher types marks with no submission.
        $row = $this->cell(['student_id' => $this->alice->id, 'item' => "assignment:{$a->id}", 'value' => 15])->assertOk();
        $this->assertEquals(30, $row->json('breakdown.assignment')); // 15/20 of 40
        $this->assertDatabaseHas('submissions', ['assignment_id' => $a->id, 'student_id' => $this->alice->id, 'returned_at' => null]);

        $this->cell(['student_id' => $this->alice->id, 'item' => "assignment:{$a->id}", 'value' => 25])->assertJsonValidationErrors('value');
        $this->cell(['student_id' => $this->teacher->id, 'item' => "assignment:{$a->id}", 'value' => 5])->assertUnprocessable();

        // Not returned yet, so Alice does not see it.
        $this->assertEquals(0, $this->actingAs($this->alice)->getJson("/api/classes/{$this->section->id}/gradebook")->json('rows.0.breakdown.assignment'));
        $this->cell(['student_id' => $this->alice->id, 'item' => "assignment:{$a->id}", 'value' => 15, 'return' => true]);
        $this->assertEquals(30, $this->actingAs($this->alice)->getJson("/api/classes/{$this->section->id}/gradebook")->json('rows.0.breakdown.assignment'));
    }

    public function test_missing_work_can_count_as_zero_and_excused_work_never_counts(): void
    {
        $done = $this->assignment(['title' => 'A1', 'due_at' => now()->subDay()]);
        $missed = $this->assignment(['title' => 'A2', 'due_at' => now()->subDay()]);
        $future = $this->assignment(['title' => 'A3', 'due_at' => now()->addWeek()]);
        Submission::create(['assignment_id' => $done->id, 'student_id' => $this->alice->id, 'marks' => 20, 'submitted_at' => now()->subDays(2)]);

        $this->assertEquals(40, $this->row($this->alice)['breakdown']['assignment']); // missing work left out

        $this->actingAs($this->teacher)->patchJson("/api/classes/{$this->section->id}/gradebook/settings", ['missing_as_zero' => true])->assertOk();
        $row = $this->row($this->alice);
        $this->assertEquals(20, $row['breakdown']['assignment']); // (100 + 0) / 2; A3 isn't due yet
        $this->assertTrue($row['cells']["assignment:{$missed->id}"]['counted_as_zero']);
        $this->assertArrayNotHasKey('counted_as_zero', $row['cells']["assignment:{$future->id}"]);

        $this->cell(['student_id' => $this->alice->id, 'item' => "assignment:{$missed->id}", 'excused' => true])->assertOk();
        $row = $this->row($this->alice);
        $this->assertEquals(40, $row['breakdown']['assignment']);
        $this->assertSame('excused', $row['cells']["assignment:{$missed->id}"]['status']);
    }

    public function test_work_for_selected_students_only_counts_for_them(): void
    {
        $a = $this->assignment(['assign_to' => 'selected', 'due_at' => now()->subDay()]);
        $a->assignedStudents()->attach($this->alice->id);
        $this->section->update(['missing_as_zero' => true]);

        $this->assertSame('not_assigned', $this->row($this->bob)['cells']["assignment:{$a->id}"]['status']);
        $this->assertTrue($this->row($this->alice)['cells']["assignment:{$a->id}"]['counted_as_zero']);
    }

    public function test_quiz_scores_can_be_entered_for_paper_quizzes(): void
    {
        $quizId = $this->actingAs($this->teacher)->postJson("/api/classes/{$this->section->id}/quizzes", [
            'title' => 'CT 1', 'duration_minutes' => 20, 'publish' => true,
            'questions' => [['type' => 'short', 'question' => 'Explain.', 'marks' => 10]],
        ])->assertCreated()->json('id');

        $this->cell(['student_id' => $this->bob->id, 'item' => "quiz:{$quizId}", 'value' => 11])->assertJsonValidationErrors('value');
        $row = $this->cell(['student_id' => $this->bob->id, 'item' => "quiz:{$quizId}", 'value' => 8])->assertOk();
        $this->assertEquals(48, $row->json('breakdown.quiz')); // 8/10 of 60
    }

    public function test_override_replaces_the_total_and_needs_a_reason(): void
    {
        $url = "/api/classes/{$this->section->id}/gradebook/overrides/{$this->alice->id}";

        $this->actingAs($this->teacher)->putJson($url, ['total' => 82])->assertJsonValidationErrors('note');
        $row = $this->actingAs($this->teacher)->putJson($url, ['total' => 82, 'note' => 'Viva performance'])->assertOk();
        $this->assertEquals(82, $row->json('total'));
        $this->assertEquals(0, $row->json('computed_total'));
        $this->assertSame('A+', $row->json('letter_grade'));

        $this->actingAs($this->teacher)->postJson("/api/classes/{$this->section->id}/gradebook/sync")->assertOk();
        $this->assertDatabaseHas('results', ['letter_grade' => 'A+']);

        $this->actingAs($this->teacher)->putJson($url, ['total' => null])->assertOk()->assertJsonPath('override', null);
        $this->actingAs($this->alice)->putJson($url, ['total' => 99, 'note' => 'x'])->assertForbidden();
    }

    public function test_export_csv(): void
    {
        $a = $this->assignment(['title' => 'Lab 1']);
        $this->cell(['student_id' => $this->alice->id, 'item' => "assignment:{$a->id}", 'value' => 10]);
        $this->cell(['student_id' => $this->bob->id, 'item' => "assignment:{$a->id}", 'excused' => true]);

        $csv = $this->actingAs($this->teacher)->get("/api/classes/{$this->section->id}/gradebook/export.csv")->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));

        $this->assertSame(['Student', 'Email', 'Lab 1 (/20)', 'Assignment (40)', 'Quiz (60)', 'Calculated total', 'Final total', 'Grade', 'Grade point', 'Override note'], $lines[0]);
        $this->assertSame(['Alice', '10'], [$lines[1][0], $lines[1][2]]);
        $this->assertSame(['Bob', 'EX'], [$lines[2][0], $lines[2][2]]);

        $this->actingAs($this->alice)->get("/api/classes/{$this->section->id}/gradebook/export.csv")->assertForbidden();
    }
}
