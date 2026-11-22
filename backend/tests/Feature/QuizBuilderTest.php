<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Department;
use App\Models\Program;
use App\Models\QuestionBankItem;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class QuizBuilderTest extends TestCase
{
    use RefreshDatabase;

    private CourseSection $section;
    private Course $course;
    private User $teacher;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'CSE', 'code' => 'CSE']);
        $program = Program::create(['department_id' => $dept->id, 'name' => 'BSc', 'code' => 'BSC']);
        $this->course = Course::create(['program_id' => $program->id, 'code' => 'CSE 101', 'title' => 'Intro', 'credit' => 3]);
        $semester = Semester::create(['name' => 'Fall', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        $this->teacher = User::factory()->teacher()->create();
        $this->student = User::factory()->create();
        $this->section = CourseSection::create([
            'course_id' => $this->course->id, 'semester_id' => $semester->id, 'teacher_id' => $this->teacher->id, 'section' => 'A',
        ]);
        Registration::create(['course_section_id' => $this->section->id, 'student_id' => $this->student->id, 'status' => 'approved']);
    }

    private function mixedQuestions(): array
    {
        return [
            ['type' => 'single', 'question' => '2 + 2?', 'options' => ['3', '4', '5'], 'correct_option' => 1, 'marks' => 1],
            ['type' => 'multiple', 'question' => 'Primes?', 'options' => ['2', '4', '5'], 'correct_options' => [0, 2], 'marks' => 2],
            ['type' => 'true_false', 'question' => 'HTTP is stateless.', 'correct_option' => 0, 'marks' => 1],
            ['type' => 'numeric', 'question' => 'Pi to 2 dp?', 'correct_numeric' => 3.14, 'tolerance' => 0.01, 'marks' => 1],
            ['type' => 'short', 'question' => 'Define normalisation.', 'marks' => 5, 'explanation' => 'Removing redundancy.'],
        ];
    }

    private function createQuiz(array $extra = []): Quiz
    {
        $id = $this->actingAs($this->teacher)->postJson("/api/classes/{$this->section->id}/quizzes", [
            'title' => 'Quiz 1', 'duration_minutes' => 20, 'publish' => true, 'questions' => $this->mixedQuestions(), ...$extra,
        ])->assertCreated()->json('id');

        return Quiz::find($id);
    }

    public function test_teacher_builds_a_quiz_with_every_question_type(): void
    {
        $quiz = $this->createQuiz();

        $this->actingAs($this->teacher)->getJson("/api/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonCount(5, 'questions')
            ->assertJsonPath('questions.2.options', ['True', 'False']) // filled in automatically
            ->assertJsonPath('questions.1.correct_options', [0, 2]);

        $this->assertEquals(10, $this->actingAs($this->teacher)->getJson("/api/quizzes/{$quiz->id}")->json('max_marks'));
    }

    public function test_questions_need_a_valid_answer_key(): void
    {
        $url = "/api/classes/{$this->section->id}/quizzes";
        $base = ['title' => 'Q', 'duration_minutes' => 10];

        $this->actingAs($this->teacher)->postJson($url, [...$base, 'questions' => [['type' => 'single', 'question' => 'x', 'options' => ['a', 'b'], 'marks' => 1]]])
            ->assertJsonValidationErrors('questions.0.correct_option');
        $this->actingAs($this->teacher)->postJson($url, [...$base, 'questions' => [['type' => 'multiple', 'question' => 'x', 'options' => ['a', 'b'], 'correct_options' => [], 'marks' => 1]]])
            ->assertJsonValidationErrors('questions.0.correct_options');
        $this->actingAs($this->teacher)->postJson($url, [...$base, 'questions' => [['type' => 'numeric', 'question' => 'x', 'marks' => 1]]])
            ->assertJsonValidationErrors('questions.0.correct_numeric');
        $this->actingAs($this->teacher)->postJson($url, [...$base, 'questions' => [['type' => 'single', 'question' => 'x', 'options' => ['only one'], 'correct_option' => 0, 'marks' => 1]]])
            ->assertJsonValidationErrors('questions.0.options');
    }

    public function test_students_never_receive_the_answer_key_and_mcqs_grade_instantly(): void
    {
        $quiz = $this->createQuiz(['shuffle_options' => true, 'show_results' => 'immediately']);

        $started = $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start")->assertOk();
        $this->assertStringNotContainsString('correct_option', $started->getContent());
        $this->assertStringNotContainsString('Removing redundancy', $started->getContent());

        // Answers use the original option index, whatever order the options were shown in.
        $ids = collect($started->json('questions'))->pluck('id', 'type');
        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/submit", ['answers' => [
            $ids['single'] => 1, $ids['multiple'] => [2, 0], $ids['true_false'] => 0, $ids['numeric'] => '3.141', $ids['short'] => 'It reduces redundancy.',
        ]])->assertOk()->assertJsonPath('score', null); // short answer still needs the teacher

        $attempt = QuizAttempt::first();
        $this->assertEquals(5, collect($attempt->results)->sum('score')); // 1 + 2 + 1 + 1 auto-graded
    }

    public function test_draw_count_gives_each_student_a_random_subset(): void
    {
        $quiz = $this->createQuiz(['draw_count' => 3]);

        $started = $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start")->assertOk();
        $this->assertCount(3, $started->json('questions'));

        // Resuming returns the same questions.
        $again = $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start")->assertOk();
        $this->assertSame(collect($started->json('questions'))->pluck('id')->all(), collect($again->json('questions'))->pluck('id')->all());

        $this->actingAs($this->teacher)->postJson("/api/classes/{$this->section->id}/quizzes", [
            'title' => 'Too many', 'duration_minutes' => 5, 'draw_count' => 9, 'questions' => $this->mixedQuestions(),
        ])->assertJsonValidationErrors('draw_count');
    }

    public function test_questions_lock_after_students_submit(): void
    {
        $quiz = $this->createQuiz();
        QuizAttempt::create(['quiz_id' => $quiz->id, 'student_id' => $this->student->id, 'started_at' => now(), 'submitted_at' => now()]);

        $this->actingAs($this->teacher)->patchJson("/api/quizzes/{$quiz->id}", ['questions' => $this->mixedQuestions()])
            ->assertJsonValidationErrors('questions');
        // Settings can still change.
        $this->actingAs($this->teacher)->patchJson("/api/quizzes/{$quiz->id}", ['title' => 'Quiz 1 (revised)'])->assertOk();
    }

    public function test_manual_results_stay_hidden_until_released(): void
    {
        $quiz = $this->createQuiz(['show_results' => 'manual', 'questions' => [
            ['type' => 'single', 'question' => '2 + 2?', 'options' => ['3', '4'], 'correct_option' => 1, 'marks' => 1],
        ]]);

        $qid = $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start")->json('questions.0.id');
        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/submit", ['answers' => [$qid => 1]])
            ->assertJsonPath('score', null);
        $this->actingAs($this->student)->getJson("/api/classes/{$this->section->id}/quizzes")->assertJsonPath('0.attempts.0.score', null);

        $this->actingAs($this->teacher)->postJson("/api/quizzes/{$quiz->id}/release-results")->assertOk();
        $this->assertEquals(1, $this->actingAs($this->student)->getJson("/api/classes/{$this->section->id}/quizzes")->json('0.attempts.0.score'));
    }

    public function test_preview_has_no_answers(): void
    {
        $quiz = $this->createQuiz();

        $preview = $this->actingAs($this->teacher)->getJson("/api/quizzes/{$quiz->id}/preview")->assertOk();
        $this->assertStringNotContainsString('correct_option', $preview->getContent());
        $this->actingAs($this->student)->getJson("/api/quizzes/{$quiz->id}/preview")->assertForbidden();
    }

    public function test_question_bank_save_and_pick(): void
    {
        $url = "/api/courses/{$this->course->id}/question-bank";

        $this->actingAs($this->teacher)->postJson($url, ['questions' => $this->mixedQuestions(), 'tags' => 'week1'])->assertCreated();
        $this->assertSame(5, QuestionBankItem::count());

        $this->actingAs($this->teacher)->postJson("{$url}/pick", ['random' => 2])->assertOk()->assertJsonCount(2);
        $this->actingAs($this->teacher)->postJson("{$url}/pick", ['random' => 10, 'type' => 'single'])->assertJsonCount(1);

        // Teachers who don't teach the course can't use its bank.
        $this->actingAs(User::factory()->teacher()->create())->getJson($url)->assertForbidden();
    }

    public function test_csv_import_parses_the_template_format(): void
    {
        $csv = "type,question,option1,option2,option3,option4,correct,marks,tolerance,explanation\n"
            ."single,2+2?,3,4,5,,2,1,,\n"
            ."multiple,Primes?,2,4,5,9,1;3,2,,\n"
            ."true_false,HTTP is stateless,,,,,true,1,,\n"
            ."numeric,Pi?,,,,,3.14,1,0.01,\n"
            ."short,Define it,,,,,,3,,\n";

        $res = $this->actingAs($this->teacher)
            ->post('/api/quiz-questions/parse-csv', ['file' => UploadedFile::fake()->createWithContent('q.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(5, 'questions');

        $this->assertSame(1, $res->json('questions.0.correct_option'));
        $this->assertSame([0, 2], $res->json('questions.1.correct_options'));

        $bad = "type,question,option1,option2,correct,marks\nsingle,Broken,a,b,7,1\n";
        $this->actingAs($this->teacher)
            ->post('/api/quiz-questions/parse-csv', ['file' => UploadedFile::fake()->createWithContent('q.csv', $bad)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');
    }
}
