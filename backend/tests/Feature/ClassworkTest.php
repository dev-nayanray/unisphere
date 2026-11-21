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
use App\Notifications\NewClassworkPosted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClassworkTest extends TestCase
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
        $this->section = CourseSection::create([
            'course_id' => $course->id, 'semester_id' => $semester->id, 'teacher_id' => $this->teacher->id, 'section' => 'A',
        ]);

        foreach (['alice', 'bob'] as $name) {
            $this->{$name} = User::factory()->create(['name' => ucfirst($name)]);
            Registration::create(['course_section_id' => $this->section->id, 'student_id' => $this->{$name}->id, 'status' => 'approved']);
        }
    }

    private function create(array $data): Assignment
    {
        $id = $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->section->id}/assignments", ['title' => 'Work', ...$data])
            ->assertCreated()
            ->json('id');

        return Assignment::find($id);
    }

    private function rubric(): array
    {
        return [
            ['title' => 'Correctness', 'levels' => [['title' => 'Excellent', 'points' => 6], ['title' => 'Partial', 'points' => 3], ['title' => 'Missing', 'points' => 0]]],
            ['title' => 'Presentation', 'levels' => [['title' => 'Clear', 'points' => 4], ['title' => 'Messy', 'points' => 1]]],
        ];
    }

    public function test_rubric_sets_points_and_grading_totals_the_levels(): void
    {
        $assignment = $this->create(['rubric' => $this->rubric()]);
        $this->assertEquals(10, $assignment->max_marks); // 6 + 4

        $submission = Submission::create(['assignment_id' => $assignment->id, 'student_id' => $this->alice->id, 'content' => 'x', 'submitted_at' => now()]);
        [$correctness, $presentation] = $assignment->criteria()->with('levels')->get();

        $this->actingAs($this->teacher)->patchJson("/api/submissions/{$submission->id}/grade", [
            'rubric_scores' => [
                $correctness->id => $correctness->levels->firstWhere('title', 'Partial')->id,
                $presentation->id => $presentation->levels->firstWhere('title', 'Clear')->id,
            ],
        ])->assertOk();

        $this->assertEquals(7, $submission->fresh()->marks); // 3 + 4

        // Every criterion needs a level.
        $this->actingAs($this->teacher)->patchJson("/api/submissions/{$submission->id}/grade", [
            'rubric_scores' => [$correctness->id => $correctness->levels->first()->id],
        ])->assertJsonValidationErrors("rubric_scores.{$presentation->id}");
    }

    public function test_rubric_cannot_change_after_grading_with_it(): void
    {
        $assignment = $this->create(['rubric' => $this->rubric()]);
        Submission::create(['assignment_id' => $assignment->id, 'student_id' => $this->alice->id, 'marks' => 5, 'rubric_scores' => [1 => 1]]);

        $this->actingAs($this->teacher)
            ->patchJson("/api/assignments/{$assignment->id}", ['rubric' => $this->rubric()])
            ->assertJsonValidationErrors('rubric');
    }

    public function test_late_penalty_is_applied_in_the_gradebook(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00'); // published before the due date
        $assignment = $this->create([
            'max_marks' => 20, 'due_at' => '2026-10-01 23:59:00', 'late_policy' => 'penalty', 'late_penalty_percent' => 10,
        ]);

        Carbon::setTestNow('2026-10-03 10:00:00'); // 2 (part) days late
        $this->actingAs($this->alice)->postJson("/api/assignments/{$assignment->id}/submit", ['content' => 'Sorry I am late'])
            ->assertCreated()
            ->assertJsonPath('late_penalty_percent', 20);
        Carbon::setTestNow();

        $submission = Submission::where('student_id', $this->alice->id)->first();
        $this->actingAs($this->teacher)->patchJson("/api/submissions/{$submission->id}/grade", ['marks' => 20])
            ->assertOk()
            ->assertJsonPath('effective_marks', 16);

        // 16/20 = 80% of the 10-point "assignment" weight = 8.
        $row = collect($this->actingAs($this->teacher)->getJson("/api/classes/{$this->section->id}/gradebook")->json('rows'))
            ->firstWhere('student.id', $this->alice->id);
        $this->assertEquals(8, $row['breakdown']['assignment']);
    }

    public function test_reject_policy_refuses_late_work(): void
    {
        $assignment = $this->create(['due_at' => now()->subDay(), 'late_policy' => 'reject']);

        $this->actingAs($this->alice)->postJson("/api/assignments/{$assignment->id}/submit", ['content' => 'Too late'])
            ->assertUnprocessable();
    }

    public function test_assign_to_selected_students_only(): void
    {
        Notification::fake();
        $assignment = $this->create(['assign_to' => 'selected', 'student_ids' => [$this->alice->id]]);

        Notification::assertSentTo($this->alice, NewClassworkPosted::class);
        Notification::assertNotSentTo($this->bob, NewClassworkPosted::class);

        $this->actingAs($this->alice)->getJson("/api/classes/{$this->section->id}/assignments")->assertJsonCount(1);
        $this->actingAs($this->bob)->getJson("/api/classes/{$this->section->id}/assignments")->assertJsonCount(0);

        $this->actingAs($this->bob)->getJson("/api/assignments/{$assignment->id}")->assertForbidden();
        $this->actingAs($this->bob)->postJson("/api/assignments/{$assignment->id}/submit", ['content' => 'x'])->assertForbidden();
        $this->actingAs($this->alice)->postJson("/api/assignments/{$assignment->id}/submit", ['content' => 'x'])->assertCreated();
    }

    public function test_only_enrolled_students_can_be_selected(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->teacher)
            ->postJson("/api/classes/{$this->section->id}/assignments", ['title' => 'X', 'assign_to' => 'selected', 'student_ids' => [$outsider->id]])
            ->assertJsonValidationErrors('student_ids.0');
    }

    public function test_scheduled_assignment_appears_and_notifies_on_time(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-01 08:00:00');

        $this->create(['status' => 'schedule', 'publish_at' => '2026-10-02 09:00:00']);
        $this->actingAs($this->alice)->getJson("/api/classes/{$this->section->id}/assignments")->assertJsonCount(0);
        Notification::assertNothingSent();

        Carbon::setTestNow('2026-10-02 09:01:00');
        $this->artisan('unisphere:publish-scheduled-posts')->assertSuccessful();

        $this->actingAs($this->alice)->getJson("/api/classes/{$this->section->id}/assignments")->assertJsonCount(1);
        Notification::assertSentTo($this->alice, NewClassworkPosted::class);
        Carbon::setTestNow();
    }

    public function test_topics_can_be_created_reordered_and_deleted(): void
    {
        $base = "/api/classes/{$this->section->id}/topics";
        $week1 = $this->actingAs($this->teacher)->postJson($base, ['name' => 'Week 1'])->assertCreated()->json('id');
        $week2 = $this->actingAs($this->teacher)->postJson($base, ['name' => 'Week 2'])->assertCreated()->json('id');
        $this->actingAs($this->teacher)->postJson($base, ['name' => 'Week 1'])->assertJsonValidationErrors('name');

        $this->actingAs($this->teacher)->putJson("{$base}/order", ['ids' => [$week2, $week1]])
            ->assertOk()
            ->assertJsonPath('0.id', $week2);

        $assignment = $this->create(['topic_id' => $week1]);
        $this->actingAs($this->teacher)->deleteJson("/api/topics/{$week1}")->assertOk();
        $this->assertNull($assignment->fresh()->topic_id);

        $this->actingAs($this->alice)->postJson($base, ['name' => 'Mine'])->assertForbidden();
    }
}
