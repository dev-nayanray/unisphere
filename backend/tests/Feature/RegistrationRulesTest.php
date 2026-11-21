<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Result;
use App\Models\Semester;
use App\Models\User;
use App\Notifications\RegistrationUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationRulesTest extends TestCase
{
    use RefreshDatabase;

    private Program $program;
    private Semester $semester;
    private User $student;
    private User $advisor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['unisphere.min_credits_per_semester' => 0]); // most tests register one course

        $dept = Department::create(['name' => 'CSE', 'code' => 'CSE']);
        $this->program = Program::create(['department_id' => $dept->id, 'name' => 'BSc CSE', 'code' => 'BSCSE']);
        $this->semester = Semester::create([
            'name' => 'Fall 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31',
            'is_current' => true, 'registration_open' => true,
        ]);

        $this->advisor = User::factory()->teacher()->create();
        $this->student = User::factory()->create();
        $this->student->student()->create([
            'program_id' => $this->program->id, 'advisor_id' => $this->advisor->id, 'student_id' => 'S-1', 'batch' => '2022',
        ]);
    }

    private function section(string $code, array $schedule = [], float $credit = 3, ?int $prerequisiteId = null, int $capacity = 40, string $label = 'A'): CourseSection
    {
        $course = Course::firstOrCreate(
            ['code' => $code],
            ['program_id' => $this->program->id, 'title' => $code, 'credit' => $credit, 'prerequisite_id' => $prerequisiteId]
        );

        return CourseSection::create([
            'course_id' => $course->id, 'semester_id' => $this->semester->id, 'section' => $label,
            'capacity' => $capacity, 'schedule' => $schedule,
        ]);
    }

    private function register(User $student, array $sections)
    {
        return $this->actingAs($student)->postJson('/api/registration', ['section_ids' => collect($sections)->pluck('id')->all()]);
    }

    /** A published result for the student in a past semester. */
    private function passed(string $code, float $gradePoint): void
    {
        $past = Semester::create(['name' => 'Spring 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-05-01']);
        $course = Course::firstOrCreate(['code' => $code], ['program_id' => $this->program->id, 'title' => $code, 'credit' => 3]);
        $section = CourseSection::create(['course_id' => $course->id, 'semester_id' => $past->id, 'section' => 'Z']);
        $registration = Registration::create(['course_section_id' => $section->id, 'student_id' => $this->student->id, 'status' => 'approved']);
        Result::create(['registration_id' => $registration->id, 'total' => 50, 'letter_grade' => 'X', 'grade_point' => $gradePoint, 'published_at' => now()]);
    }

    public function test_a_valid_registration_goes_to_the_advisor(): void
    {
        $section = $this->section('CSE 101');

        $this->register($this->student, [$section])->assertCreated();

        $this->assertDatabaseHas('registrations', ['course_section_id' => $section->id, 'student_id' => $this->student->id, 'status' => 'pending']);
    }

    public function test_registration_is_closed_after_the_add_drop_deadline(): void
    {
        $this->semester->update(['registration_deadline' => now()->subDay()->toDateString()]);

        $this->register($this->student, [$this->section('CSE 101')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('section_ids');
    }

    public function test_prerequisite_must_be_passed(): void
    {
        $intro = Course::create(['program_id' => $this->program->id, 'code' => 'CSE 100', 'title' => 'Intro', 'credit' => 3]);
        $advanced = $this->section('CSE 200', prerequisiteId: $intro->id);

        $this->register($this->student, [$advanced])->assertJsonValidationErrors("sections.{$advanced->id}");

        $this->passed('CSE 100', 3.0);
        $this->register($this->student, [$advanced])->assertCreated();
    }

    public function test_credit_limits(): void
    {
        config(['unisphere.max_credits_per_semester' => 6, 'unisphere.min_credits_per_semester' => 4]);

        $this->register($this->student, [$this->section('CSE 101')])
            ->assertJsonValidationErrors('section_ids'); // 3 < 4

        $three = [$this->section('CSE 102'), $this->section('CSE 103'), $this->section('CSE 104')];
        $this->register($this->student, $three)->assertJsonValidationErrors('section_ids'); // 9 > 6
    }

    public function test_only_one_section_of_a_course(): void
    {
        $a = $this->section('CSE 101', label: 'A');
        $b = $this->section('CSE 101', label: 'B');

        $this->register($this->student, [$a, $b])->assertJsonValidationErrors('section_ids');
    }

    public function test_time_clashes_are_refused(): void
    {
        $a = $this->section('CSE 101', [['day' => 'Sun', 'start' => '09:00', 'end' => '10:30']]);
        $b = $this->section('CSE 102', [['day' => 'Sun', 'start' => '10:00', 'end' => '11:30']]);
        $c = $this->section('CSE 103', [['day' => 'Sun', 'start' => '10:30', 'end' => '12:00']]); // touching is fine

        $this->register($this->student, [$a, $b])->assertJsonValidationErrors("sections.{$b->id}");
        $this->register($this->student, [$a, $c])->assertCreated();
    }

    public function test_overdue_fees_block_registration(): void
    {
        Invoice::create(['student_id' => $this->student->id, 'title' => 'Tuition', 'amount' => 1000, 'due_date' => now()->subWeek()->toDateString()]);

        $this->register($this->student, [$this->section('CSE 101')])->assertJsonValidationErrors('section_ids');

        config(['unisphere.block_registration_on_overdue_fees' => false]);
        $this->register($this->student, [$this->section('CSE 102')])->assertCreated();
    }

    public function test_passed_courses_can_only_be_retaken_for_grade_improvement(): void
    {
        $this->passed('CSE 101', 3.75); // A
        $this->register($this->student, [$this->section('CSE 101')])->assertUnprocessable();

        $this->passed('CSE 102', 2.25); // C
        $section = $this->section('CSE 102');
        $this->register($this->student, [$section])->assertCreated();
        $this->assertTrue(Registration::where('course_section_id', $section->id)->first()->is_retake);
    }

    public function test_full_sections_waitlist_and_promote_when_a_seat_frees(): void
    {
        Notification::fake();
        $section = $this->section('CSE 101', capacity: 1);

        $first = User::factory()->create();
        $first->student()->create(['program_id' => $this->program->id, 'student_id' => 'S-2', 'batch' => '2022']);

        $this->register($first, [$section])->assertCreated();
        $this->register($this->student, [$section])->assertCreated();

        $waiting = Registration::where('student_id', $this->student->id)->first();
        $this->assertSame('waitlisted', $waiting->status);

        $this->actingAs($this->student)->getJson('/api/registration/mine')->assertJsonPath('0.waitlist_position', 1);

        // The first student drops: the waitlisted student moves up.
        $held = Registration::where('student_id', $first->id)->first();
        $this->actingAs($first)->deleteJson("/api/registration/{$held->id}")->assertOk();

        $this->assertSame('pending', $waiting->fresh()->status);
        Notification::assertSentTo($this->student, RegistrationUpdated::class);
    }

    public function test_dropped_course_can_be_added_again_without_duplicates(): void
    {
        $section = $this->section('CSE 101');
        $this->register($this->student, [$section])->assertCreated();

        $registration = Registration::first();
        $this->actingAs($this->student)->deleteJson("/api/registration/{$registration->id}")->assertOk();
        $this->register($this->student, [$section])->assertCreated();

        $this->assertSame(1, Registration::count());
        $this->assertSame('pending', $registration->fresh()->status);
    }

    public function test_advisor_approves_many_with_a_note(): void
    {
        Notification::fake();
        $this->register($this->student, [$this->section('CSE 101'), $this->section('CSE 102')])->assertCreated();

        $this->actingAs($this->advisor)
            ->patchJson('/api/registration/decide-many', [
                'ids' => Registration::pluck('id')->all(),
                'status' => 'approved',
                'note' => 'Good plan.',
            ])
            ->assertOk();

        $this->assertSame(2, Registration::where('status', 'approved')->where('note', 'Good plan.')->count());
        Notification::assertSentToTimes($this->student, RegistrationUpdated::class, 2);
    }

    public function test_other_teachers_cannot_decide(): void
    {
        $this->register($this->student, [$this->section('CSE 101')])->assertCreated();

        $this->actingAs(User::factory()->teacher()->create())
            ->patchJson('/api/registration/'.Registration::first()->id.'/decide', ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_registrar_can_force_add_to_a_full_section(): void
    {
        $section = $this->section('CSE 101', capacity: 0);

        $this->actingAs(User::factory()->create(['role' => 'registrar']))
            ->postJson('/api/registration/force', ['student' => 'S-1', 'section_id' => $section->id])
            ->assertCreated();

        $this->assertDatabaseHas('registrations', ['student_id' => $this->student->id, 'status' => 'approved', 'forced' => true]);
    }
}
