<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Department;
use App\Models\Program;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizTakingTest extends TestCase
{
    use RefreshDatabase;

    private CourseSection $section;
    private User $teacher;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'CSE', 'code' => 'CSE']);
        $program = Program::create(['department_id' => $dept->id, 'name' => 'BSc', 'code' => 'BSC']);
        $course = Course::create(['program_id' => $program->id, 'code' => 'CSE 101', 'title' => 'Intro', 'credit' => 3]);
        $semester = Semester::create(['name' => 'Fall', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        $this->teacher = User::factory()->teacher()->create();
        $this->student = User::factory()->create();
        $this->section = CourseSection::create([
            'course_id' => $course->id, 'semester_id' => $semester->id, 'teacher_id' => $this->teacher->id, 'section' => 'A',
        ]);
        Registration::create(['course_section_id' => $this->section->id, 'student_id' => $this->student->id, 'status' => 'approved']);
    }

    private function quiz(array $extra = []): Quiz
    {
        $id = $this->actingAs($this->teacher)->postJson("/api/classes/{$this->section->id}/quizzes", [
            'title' => 'Quiz', 'duration_minutes' => 10, 'publish' => true, 'show_results' => 'immediately',
            'questions' => [
                ['type' => 'single', 'question' => '2 + 2?', 'options' => ['3', '4'], 'correct_option' => 1, 'marks' => 2],
                ['type' => 'short', 'question' => 'Explain TCP.', 'marks' => 3],
            ],
            ...$extra,
        ])->assertCreated()->json('id');

        return Quiz::find($id);
    }

    private function start(Quiz $quiz)
    {
        return $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start")->assertOk();
    }

    public function test_the_server_sets_the_deadline_and_answers_autosave(): void
    {
        $quiz = $this->quiz();
        $started = $this->start($quiz);
        $this->assertEqualsWithDelta(600, $started->json('attempt.seconds_left'), 2);

        $ids = collect($started->json('questions'))->pluck('id');
        $this->actingAs($this->student)->patchJson("/api/quizzes/{$quiz->id}/answers", ['answers' => [$ids[0] => 1, 999 => 'junk']])->assertOk();

        $attempt = QuizAttempt::first();
        $this->assertSame([$ids[0] => 1], $attempt->answers); // answers to other questions are dropped

        // Resuming returns the saved answers and the same deadline.
        $this->travel(3)->minutes();
        $again = $this->start($quiz);
        $this->assertSame([(string) $ids[0] => 1], $again->json('attempt.answers'));
        $this->assertEqualsWithDelta(420, $again->json('attempt.seconds_left'), 2);
    }

    public function test_the_deadline_never_passes_the_closing_time(): void
    {
        $quiz = $this->quiz(['duration_minutes' => 60, 'ends_at' => now()->addMinutes(5)->toDateTimeString()]);
        $this->assertEqualsWithDelta(300, $this->start($quiz)->json('attempt.seconds_left'), 60);
    }

    public function test_late_submissions_only_count_the_last_autosave(): void
    {
        $quiz = $this->quiz();
        $qid = $this->start($quiz)->json('questions.0.id');
        $this->actingAs($this->student)->patchJson("/api/quizzes/{$quiz->id}/answers", ['answers' => [$qid => 0]])->assertOk(); // wrong

        $this->travel(11)->minutes();
        $this->actingAs($this->student)->patchJson("/api/quizzes/{$quiz->id}/answers", ['answers' => [$qid => 1]])->assertUnprocessable();
        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/submit", ['answers' => [$qid => 1]])
            ->assertOk()->assertJsonPath('message', 'Time was up, so your last saved answers were submitted.');

        $attempt = QuizAttempt::first();
        $this->assertTrue($attempt->auto_submitted);
        $this->assertEquals(0, $attempt->results[$qid]['score']);
    }

    public function test_abandoned_attempts_are_submitted_by_the_scheduler(): void
    {
        $quiz = $this->quiz();
        $qid = $this->start($quiz)->json('questions.0.id');
        $this->actingAs($this->student)->patchJson("/api/quizzes/{$quiz->id}/answers", ['answers' => [$qid => 1]]);

        $this->travel(15)->minutes();
        $this->artisan('unisphere:submit-expired-quiz-attempts')->assertSuccessful();

        $attempt = QuizAttempt::first();
        $this->assertNotNull($attempt->submitted_at);
        $this->assertEquals(2, $attempt->results[$qid]['score']);
    }

    public function test_integrity_events_are_counted_for_the_teacher(): void
    {
        $quiz = $this->quiz();
        $this->start($quiz);
        foreach (['blur', 'blur', 'paste'] as $type) {
            $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/events", ['type' => $type])->assertOk();
        }
        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/events", ['type' => 'hack'])->assertJsonValidationErrors('type');

        $this->actingAs($this->teacher)->getJson("/api/quizzes/{$quiz->id}/attempts")
            ->assertOk()
            ->assertJsonPath('attempts.0.integrity.blur', 2)
            ->assertJsonPath('attempts.0.integrity.paste', 1);
    }

    public function test_teacher_marks_written_answers_and_the_student_sees_the_review(): void
    {
        $quiz = $this->quiz();
        $ids = collect($this->start($quiz)->json('questions'))->pluck('id', 'type');
        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/submit", ['answers' => [$ids['single'] => 1, $ids['short'] => 'Reliable stream.']])
            ->assertJsonPath('score', null);

        $attempt = QuizAttempt::first();
        $list = $this->actingAs($this->teacher)->getJson("/api/quizzes/{$quiz->id}/attempts")->assertJsonPath('attempts.0.needs_grading', true);
        $this->assertEquals(100, collect($list->json('stats'))->firstWhere('type', 'single')['percent_correct']);

        $this->actingAs($this->teacher)->patchJson("/api/quiz-attempts/{$attempt->id}/answers", ['scores' => [$ids['short'] => 9]])
            ->assertJsonValidationErrors("scores.{$ids['short']}");
        $graded = $this->actingAs($this->teacher)->patchJson("/api/quiz-attempts/{$attempt->id}/answers", ['scores' => [$ids['short'] => 2.5]])->assertOk();
        $this->assertEquals(4.5, $graded->json('score'));

        $result = $this->actingAs($this->student)->getJson("/api/quizzes/{$quiz->id}/result")->assertOk()->assertJsonPath('results_visible', true);
        $this->assertEquals(4.5, $result->json('score'));
        $single = collect($result->json('questions'))->firstWhere('type', 'single');
        $this->assertSame(1, $single['correct_option']);
        $this->assertSame(1, $single['answer']);
    }

    public function test_results_after_close_are_hidden_until_the_quiz_closes(): void
    {
        $quiz = $this->quiz(['show_results' => 'after_close', 'ends_at' => now()->addMinutes(30)->toDateTimeString()]);
        $qid = $this->start($quiz)->json('questions.0.id');
        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/submit", ['answers' => [$qid => 1]]);

        $this->actingAs($this->student)->getJson("/api/quizzes/{$quiz->id}/result")
            ->assertJsonPath('results_visible', false)->assertJsonMissingPath('questions');

        $this->travel(31)->minutes();
        $this->actingAs($this->student)->getJson("/api/quizzes/{$quiz->id}/result")->assertJsonPath('results_visible', true);
    }

    public function test_teacher_can_reset_an_attempt_for_a_retake(): void
    {
        $quiz = $this->quiz();
        $this->start($quiz);
        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/submit", ['answers' => []])->assertOk();
        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start")->assertUnprocessable();

        $attempt = QuizAttempt::first();
        $this->actingAs($this->student)->deleteJson("/api/quiz-attempts/{$attempt->id}")->assertForbidden();
        $this->actingAs($this->teacher)->deleteJson("/api/quiz-attempts/{$attempt->id}")->assertOk();

        $this->start($quiz);
    }
}
