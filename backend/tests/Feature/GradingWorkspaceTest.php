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
use App\Notifications\GradesReturned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class GradingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private CourseSection $section;
    private Assignment $assignment;
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

        foreach (['alice' => 'S-001', 'bob' => 'S-002'] as $name => $code) {
            $this->{$name} = User::factory()->create(['name' => ucfirst($name)]);
            $this->{$name}->student()->create(['program_id' => $program->id, 'student_id' => $code, 'batch' => '2022']);
            Registration::create(['course_section_id' => $this->section->id, 'student_id' => $this->{$name}->id, 'status' => 'approved']);
        }

        $this->assignment = Assignment::create([
            'course_section_id' => $this->section->id, 'author_id' => $this->teacher->id, 'title' => 'Lab 1',
            'max_marks' => 20, 'published_at' => now()->subDay(), 'notified_at' => now(),
        ]);
    }

    private function submit(User $student, array $data = ['content' => 'My answer'])
    {
        return $this->actingAs($student)->post("/api/assignments/{$this->assignment->id}/submit", $data, ['Accept' => 'application/json']);
    }

    public function test_marks_stay_hidden_until_returned(): void
    {
        Notification::fake();
        $this->submit($this->alice)->assertCreated();
        $submission = Submission::first();

        $this->actingAs($this->teacher)->patchJson("/api/submissions/{$submission->id}/grade", ['marks' => 18, 'feedback' => 'Nice'])->assertOk();

        $this->actingAs($this->alice)->getJson("/api/assignments/{$this->assignment->id}")
            ->assertJsonPath('submissions.0.marks', null)
            ->assertJsonPath('submissions.0.feedback', null);
        $this->actingAs($this->alice)->getJson("/api/classes/{$this->section->id}/assignments")->assertJsonPath('0.submissions.0.marks', null);
        $this->assertEquals(0, $this->actingAs($this->alice)->getJson("/api/classes/{$this->section->id}/gradebook")->json('rows.0.breakdown.assignment'));

        $this->actingAs($this->teacher)->postJson("/api/assignments/{$this->assignment->id}/return")->assertOk();

        $this->actingAs($this->alice)->getJson("/api/assignments/{$this->assignment->id}")
            ->assertJsonPath('submissions.0.feedback', 'Nice');
        $this->assertEquals(18, $this->actingAs($this->alice)->getJson("/api/assignments/{$this->assignment->id}")->json('submissions.0.effective_marks'));
        Notification::assertSentTo($this->alice, GradesReturned::class);
    }

    public function test_unsubmit_and_resubmit_keeps_every_version(): void
    {
        $this->submit($this->alice, ['content' => 'First try'])->assertCreated();
        $this->submit($this->alice, ['content' => 'Again'])->assertUnprocessable(); // must unsubmit first

        $this->actingAs($this->alice)->postJson("/api/assignments/{$this->assignment->id}/unsubmit")->assertOk();
        $this->submit($this->alice, ['content' => 'Second try'])->assertCreated();

        $submission = Submission::first();
        $this->assertSame('Second try', $submission->content);
        $this->assertSame(2, $submission->versions()->count());

        // Once returned, the work is locked.
        $submission->update(['marks' => 15, 'returned_at' => now()]);
        $this->actingAs($this->alice)->postJson("/api/assignments/{$this->assignment->id}/unsubmit")->assertUnprocessable();
    }

    public function test_workspace_lists_every_student_with_a_state(): void
    {
        $this->submit($this->alice)->assertCreated();

        $this->actingAs($this->teacher)->getJson("/api/assignments/{$this->assignment->id}/workspace")
            ->assertOk()
            ->assertJsonCount(2, 'rows')
            ->assertJsonPath('counts.turned_in', 1)
            ->assertJsonPath('counts.missing', 1);

        $this->actingAs($this->alice)->getJson("/api/assignments/{$this->assignment->id}/workspace")->assertForbidden();
    }

    public function test_files_are_private_and_preview_through_signed_links(): void
    {
        Storage::fake('local');
        $url = $this->submit($this->alice, ['file' => UploadedFile::fake()->create('report.pdf', 50, 'application/pdf')])
            ->assertCreated()
            ->json('file_url');

        $this->get($url)->assertOk();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=x', $url))->assertForbidden();
    }

    public function test_download_all_submissions_as_zip_named_by_student_id(): void
    {
        Storage::fake('local');
        $this->submit($this->alice, ['content' => 'Answer A', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertCreated();
        $this->submit($this->bob, ['content' => 'Answer B'])->assertCreated();

        $response = $this->actingAs($this->teacher)->get("/api/assignments/{$this->assignment->id}/submissions.zip")->assertOk();

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->all();
        $zip->close();

        $this->assertContains('s-001-alice/answer.txt', $names);
        $this->assertContains('s-001-alice/a.pdf', $names);
        $this->assertContains('s-002-bob/answer.txt', $names);
    }

    public function test_marks_csv_round_trip(): void
    {
        $this->actingAs($this->teacher)->get("/api/assignments/{$this->assignment->id}/marks.csv")->assertOk();

        $csv = "student_id,name,marks,feedback\nS-001,Alice,17,Good\nS-002,Bob,,\n";
        $this->actingAs($this->teacher)
            ->post("/api/assignments/{$this->assignment->id}/marks-import", ['file' => UploadedFile::fake()->createWithContent('marks.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertEquals(17, Submission::where('student_id', $this->alice->id)->value('marks'));
        $this->assertNull(Submission::where('student_id', $this->bob->id)->first()); // empty cell skipped

        $bad = "student_id,marks\nS-999,10\nS-002,25\n";
        $this->actingAs($this->teacher)
            ->post("/api/assignments/{$this->assignment->id}/marks-import", ['file' => UploadedFile::fake()->createWithContent('marks.csv', $bad)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');
    }

    public function test_comment_bank_is_private_to_each_teacher(): void
    {
        $id = $this->actingAs($this->teacher)->postJson('/api/comment-bank', ['text' => 'Cite your sources.'])->assertCreated()->json('id');
        $this->actingAs($this->teacher)->getJson('/api/comment-bank')->assertJsonCount(1);

        $other = User::factory()->teacher()->create();
        $this->actingAs($other)->getJson('/api/comment-bank')->assertJsonCount(0);
        $this->actingAs($other)->deleteJson("/api/comment-bank/{$id}")->assertForbidden();
        $this->actingAs($this->teacher)->deleteJson("/api/comment-bank/{$id}")->assertOk();
    }
}
