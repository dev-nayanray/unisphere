<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Department;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\User;
use App\Support\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RoutineTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;
    private Program $program;
    private User $teacher;
    private User $student;
    private CourseSection $section;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'CSE', 'code' => 'CSE']);
        $this->program = Program::create(['department_id' => $dept->id, 'name' => 'BSc CSE', 'code' => 'BSCSE']);
        // 2026-09-01 is a Tuesday.
        $this->semester = Semester::create(['name' => 'Fall 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'is_current' => true]);
        $this->teacher = User::factory()->teacher()->create();
        $this->student = User::factory()->create();

        $this->section = $this->makeSection('CSE 101', '701', $this->teacher->id, [
            ['day' => 'Sun', 'start' => '09:00', 'end' => '10:30'],
            ['day' => 'Tue', 'start' => '09:00', 'end' => '10:30'],
        ]);
        Registration::create(['course_section_id' => $this->section->id, 'student_id' => $this->student->id, 'status' => 'approved']);
    }

    private function makeSection(string $code, ?string $room, ?int $teacherId, array $schedule): CourseSection
    {
        $course = Course::create(['program_id' => $this->program->id, 'code' => $code, 'title' => $code, 'credit' => 3]);

        return CourseSection::create([
            'course_id' => $course->id, 'semester_id' => $this->semester->id, 'section' => 'A',
            'room' => $room, 'teacher_id' => $teacherId, 'schedule' => $schedule,
        ]);
    }

    public function test_student_and_teacher_see_their_weekly_slots(): void
    {
        $this->actingAs($this->student)->getJson('/api/routine/me')
            ->assertOk()
            ->assertJsonCount(2, 'slots')
            ->assertJsonPath('slots.0.code', 'CSE 101')
            ->assertJsonPath('slots.0.day', 'Sun');

        $this->actingAs($this->teacher)->getJson('/api/routine/me')->assertOk()->assertJsonCount(2, 'slots');
    }

    public function test_room_routine(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'registrar']))
            ->getJson('/api/routine/rooms/701')
            ->assertOk()
            ->assertJsonCount(2, 'slots');
    }

    public function test_room_and_teacher_clashes_are_blocked(): void
    {
        $registrar = User::factory()->create(['role' => 'registrar']);
        $other = Course::create(['program_id' => $this->program->id, 'code' => 'CSE 102', 'title' => 'X', 'credit' => 3]);
        $base = ['course_id' => $other->id, 'semester_id' => $this->semester->id, 'section' => 'A'];

        // Same room, overlapping time.
        $this->actingAs($registrar)->postJson('/api/admin/sections', [
            ...$base, 'room' => '701', 'schedule' => [['day' => 'Sun', 'start' => '10:00', 'end' => '11:00']],
        ])->assertJsonValidationErrors('schedule');

        // Same teacher, overlapping time, different room.
        $res = $this->actingAs($registrar)->postJson('/api/admin/sections', [
            ...$base, 'room' => '702', 'teacher_id' => $this->teacher->id,
            'schedule' => [['day' => 'Tue', 'start' => '09:30', 'end' => '11:00']],
        ])->assertJsonValidationErrors('schedule');
        $this->assertStringContainsString('teaches CSE 101', $res->json('errors.schedule.0'));

        // Back-to-back in the same room is fine.
        $this->actingAs($registrar)->postJson('/api/admin/sections', [
            ...$base, 'room' => '701', 'schedule' => [['day' => 'Sun', 'start' => '10:30', 'end' => '12:00']],
        ])->assertCreated();
    }

    public function test_ics_export_repeats_weekly_from_the_first_class(): void
    {
        $ics = $this->actingAs($this->student)->get('/api/routine/me.ics')->assertOk()->getContent();

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;UNTIL=', $ics);
        // Semester starts Tue 1 Sep: the Tuesday class starts that day, the Sunday class on 6 Sep.
        $this->assertStringContainsString('DTSTART;TZID=Asia/Dhaka:20260901T090000', $ics);
        $this->assertStringContainsString('DTSTART;TZID=Asia/Dhaka:20260906T090000', $ics);
    }

    public function test_dashboard_lists_todays_classes(): void
    {
        Carbon::setTestNow('2026-09-06 08:00:00'); // a Sunday

        $this->actingAs($this->student)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'today')
            ->assertJsonPath('today.0.start', '09:00');

        Carbon::setTestNow();
    }

    public function test_schedule_helpers(): void
    {
        $this->assertTrue(Schedule::slotsOverlap(
            ['day' => 'Sun', 'start' => '09:00', 'end' => '10:30'],
            ['day' => 'Sun', 'start' => '10:00', 'end' => '11:00'],
        ));
        $this->assertFalse(Schedule::slotsOverlap(
            ['day' => 'Sun', 'start' => '09:00', 'end' => '10:30'],
            ['day' => 'Sun', 'start' => '10:30', 'end' => '11:00'],
        ));
        $this->assertSame('2026-09-05', Schedule::firstOccurrence('Sat', new \DateTimeImmutable('2026-09-01'))->toDateString());
    }
}
