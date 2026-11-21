<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Department;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AdmissionDecision;
use App\Services\StudentIdGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrar = User::factory()->create(['role' => 'registrar']);
        $dept = Department::create(['name' => 'CSE', 'code' => 'CSE']);
        $this->program = Program::create(['department_id' => $dept->id, 'name' => 'BSc CSE', 'code' => 'BSCSE']);
        User::factory()->teacher()->create(['email' => 'advisor@test.com']);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('students.csv', $content);
    }

    public function test_preview_reports_errors_per_line_without_saving(): void
    {
        User::factory()->create(['email' => 'taken@test.com']);

        $csv = "name,email,phone,student_id,program_code,batch,advisor_email\n"
            ."Good Student,good@test.com,01712345678,,BSCSE,2026,advisor@test.com\n"
            ."Taken Email,taken@test.com,,,BSCSE,2026,\n"
            ."Bad Program,bad@test.com,,,XYZ,2026,\n"
            ."Duplicate,good@test.com,123,,BSCSE,2026,nobody@test.com\n";

        $res = $this->actingAs($this->registrar)
            ->post('/api/admin/import/students/preview', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('valid', 1)
            ->assertJsonPath('invalid', 3);

        $rows = $res->json('rows');
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame([], $rows[0]['errors']);
        $this->assertStringContainsString('already exists', implode(' ', $rows[1]['errors']));
        $this->assertStringContainsString('Unknown program', implode(' ', $rows[2]['errors']));
        $this->assertStringContainsString('repeats line 2', implode(' ', $rows[3]['errors']));
        $this->assertDatabaseMissing('users', ['email' => 'good@test.com']);
    }

    public function test_import_refuses_files_with_errors(): void
    {
        $csv = "name,email,program_code\nNo Program,np@test.com,XYZ\n";

        $this->actingAs($this->registrar)
            ->post('/api/admin/import/students', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('invalid', 1);

        $this->assertDatabaseMissing('users', ['email' => 'np@test.com']);
    }

    public function test_importing_the_sample_file_creates_50_students(): void
    {
        $sample = file_get_contents(base_path('../docs/samples/students-sample.csv'));
        // The sample's advisor is the seeded demo teacher.
        User::factory()->teacher()->create(['email' => 'teacher@unisphere.test']);

        $res = $this->actingAs($this->registrar)
            ->post('/api/admin/import/students', ['file' => $this->csv($sample)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('created', 50);

        $this->assertSame(50, Student::count());
        $this->assertCount(50, $res->json('credentials'));
        $this->assertTrue(User::where('email', $res->json('credentials.0.email'))->first()->must_change_password);
    }

    public function test_faculty_import(): void
    {
        $csv = "name,email,phone,employee_id,department_code,designation,role\n"
            ."Dr. Kamal,kamal@test.com,,F-9001,CSE,Professor,department_head\n";

        $this->actingAs($this->registrar)
            ->post('/api/admin/import/faculty', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])
            ->assertCreated();

        $user = User::where('email', 'kamal@test.com')->first();
        $this->assertSame('department_head', $user->role->value);
        $this->assertSame('F-9001', $user->faculty->employee_id);
    }

    public function test_student_ids_follow_the_configured_format(): void
    {
        $ids = app(StudentIdGenerator::class);
        $date = new \DateTimeImmutable('2026-10-05'); // Fall term
        $pp = str_pad((string) $this->program->id, 2, '0', STR_PAD_LEFT);

        $first = $ids->next($this->program, $date);
        $this->assertSame("2026-3-{$pp}-001", $first);

        Student::create(['user_id' => User::factory()->create()->id, 'program_id' => $this->program->id, 'student_id' => $first, 'batch' => '2026']);
        $this->assertSame("2026-3-{$pp}-002", $ids->next($this->program, $date));
    }

    public function test_template_download(): void
    {
        $this->actingAs($this->registrar)
            ->get('/api/admin/import/students/template')
            ->assertOk()
            ->assertDownload('unisphere-students-template.csv');
    }

    public function test_application_can_be_submitted_and_accepted_end_to_end(): void
    {
        Storage::fake('local');
        Notification::fake();

        $code = $this->post('/api/applications', [
            'program_id' => $this->program->id,
            'name' => 'Rafi Islam',
            'email' => 'rafi@test.com',
            'phone' => '01912345678',
            'date_of_birth' => '2006-04-12',
            'gender' => 'male',
            'address' => 'Mirpur, Dhaka',
            'ssc_gpa' => 5.00,
            'ssc_year' => now()->year - 3,
            'hsc_gpa' => 4.83,
            'hsc_year' => now()->year - 1,
            'documents' => [UploadedFile::fake()->create('hsc.pdf', 200, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('tracking_code');

        $this->postJson('/api/applications/status', ['tracking_code' => $code, 'email' => 'rafi@test.com'])
            ->assertOk()
            ->assertJsonPath('status', 'submitted');

        $application = Application::where('tracking_code', $code)->first();
        Storage::disk('local')->assertExists($application->documents[0]['path']);

        $this->actingAs($this->registrar)
            ->get("/api/admin/applications/{$application->id}/documents/0")
            ->assertOk();

        $res = $this->actingAs($this->registrar)
            ->postJson("/api/admin/applications/{$application->id}/decide", ['status' => 'accepted', 'note' => 'Welcome!'])
            ->assertOk();

        $user = User::where('email', 'rafi@test.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($res->json('student_id'), $user->student->student_id);
        $this->assertTrue($user->must_change_password);

        Notification::assertSentOnDemand(AdmissionDecision::class);

        // A decided application cannot be decided again.
        $this->actingAs($this->registrar)
            ->postJson("/api/admin/applications/{$application->id}/decide", ['status' => 'rejected'])
            ->assertUnprocessable();
    }

    public function test_status_needs_the_matching_email(): void
    {
        $application = Application::create([
            'program_id' => $this->program->id, 'name' => 'X', 'email' => 'x@test.com', 'phone' => '01712345678',
            'date_of_birth' => '2006-01-01', 'gender' => 'male', 'address' => 'Dhaka', 'ssc_gpa' => 5, 'ssc_year' => 2022,
            'hsc_gpa' => 5, 'hsc_year' => 2024,
        ]);

        $this->postJson('/api/applications/status', ['tracking_code' => $application->tracking_code, 'email' => 'other@test.com'])
            ->assertNotFound();
    }

    public function test_student_export_is_a_csv(): void
    {
        $res = $this->actingAs($this->registrar)->get('/api/admin/students/export')->assertOk();

        $this->assertStringStartsWith('text/csv', $res->headers->get('content-type'));
    }
}
