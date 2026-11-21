<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AttendanceSession;
use App\Models\Complaint;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Notice;
use App\Models\Post;
use App\Models\Program;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Calls every authenticated API route as each role (and as a guest) and checks
 * who gets through. "Allowed" means the request passes authorization: it may
 * still answer 404/422 because the test sends no body, but never 401/403/5xx.
 *
 * The fixture university: the teacher teaches the section and advises the
 * student, the department head heads the course's department, the student is
 * enrolled, and the registrar wrote the notice.
 *
 * docs/permissions.md is the human-readable copy of this matrix.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const SA = 'super_admin';
    private const REG = 'registrar';
    private const HEAD = 'department_head';
    private const TEA = 'teacher';
    private const ACC = 'accounts';
    private const STU = 'student';

    private const ALL = [self::SA, self::REG, self::HEAD, self::TEA, self::ACC, self::STU];
    private const MEMBERS = [self::SA, self::REG, self::HEAD, self::TEA, self::STU];
    private const MANAGE = [self::SA, self::REG, self::TEA];
    private const STAFF = [self::SA, self::REG, self::HEAD, self::TEA];
    private const ADMIN = [self::SA, self::REG];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, int|string> */
    private array $ids = [];

    /** route => roles allowed. Keys are "METHOD uri" exactly as Laravel registers them. */
    public static function matrix(): array
    {
        $m = [
            // Account
            'GET auth/me' => self::ALL,
            'POST auth/logout' => self::ALL,
            'PUT auth/password' => self::ALL,
            'PUT auth/profile' => self::ALL,
            'POST auth/avatar' => self::ALL,
            'GET auth/activity' => self::ALL,
            'GET dashboard' => self::ALL,
            'GET notifications' => self::ALL,
            'POST notifications/read-all' => self::ALL,

            // Notices & help desk
            'GET notices' => self::ALL,
            'GET notices/{notice}' => self::ALL,
            'POST notices' => [self::SA, self::REG, self::HEAD, self::TEA],
            'DELETE notices/{notice}' => self::ADMIN, // registrar is the author
            'GET complaints' => self::ALL,
            'POST complaints' => self::ALL,
            'GET complaints/{complaint}' => [self::SA, self::REG, self::STU], // student filed it
            'PUT complaints/{complaint}' => self::ADMIN,
            'PATCH complaints/{complaint}' => self::ADMIN,

            // Virtual classroom
            'GET classes' => self::ALL,
            'POST classes/join' => [self::SA, self::STU],
            'GET classes/{section}' => self::MEMBERS,
            'GET classes/{section}/people' => self::MEMBERS,
            'POST classes/{section}/join-code' => self::MANAGE,
            'GET classes/{section}/posts' => self::MEMBERS,
            'POST classes/{section}/posts' => self::MEMBERS,
            'GET classes/{section}/materials' => self::MEMBERS,
            'POST classes/{section}/materials' => self::MANAGE,
            'GET classes/{section}/assignments' => self::MEMBERS,
            'POST classes/{section}/assignments' => self::MANAGE,
            'GET classes/{section}/quizzes' => self::MEMBERS,
            'POST classes/{section}/quizzes' => self::MANAGE,
            'GET classes/{section}/gradebook' => self::MEMBERS,
            'GET classes/{section}/gradebook/summary' => self::MEMBERS,
            'PUT classes/{section}/gradebook/scheme' => self::MANAGE,
            'POST classes/{section}/gradebook/sync' => self::MANAGE,
            'GET classes/{section}/attendance' => self::MANAGE,
            'POST classes/{section}/attendance' => self::MANAGE,
            'GET classes/{section}/attendance/report' => self::MEMBERS,
            'GET classes/{section}/results' => self::STAFF,
            'POST classes/{section}/results/publish' => [self::SA, self::REG, self::HEAD],

            'PATCH posts/{post}' => self::MANAGE, // teacher is the author
            'DELETE posts/{post}' => self::MANAGE,
            'POST posts/{post}/comments' => self::MEMBERS,
            'DELETE materials/{material}' => self::MANAGE,

            'GET assignments/{assignment}' => self::MEMBERS,
            'PATCH assignments/{assignment}' => self::MANAGE,
            'DELETE assignments/{assignment}' => self::MANAGE,
            'POST assignments/{assignment}/submit' => [self::STU],
            'POST assignments/{assignment}/grades' => self::MANAGE,
            'PATCH submissions/{submission}/grade' => self::MANAGE,

            'GET quizzes/{quiz}' => self::MEMBERS,
            'POST quizzes/{quiz}/start' => [self::STU],
            'POST quizzes/{quiz}/submit' => [self::STU],
            'PATCH quiz-attempts/{attempt}/grade' => self::MANAGE,

            // Attendance
            'GET attendance-sessions/{session}' => self::MANAGE,
            'PUT attendance-sessions/{session}/records' => self::MANAGE,
            'POST attendance/check-in' => [self::STU],

            // Registration & results
            'GET registration/offered' => [self::SA, self::STU],
            'GET registration/mine' => [self::SA, self::STU],
            'POST registration' => [self::SA, self::STU],
            'DELETE registration/{registration}' => [self::STU],
            'GET results/mine' => [self::SA, self::STU],
            'GET registration/pending' => self::STAFF,
            'PATCH registration/{registration}/decide' => self::STAFF, // teacher is the advisor

            // Fees
            'GET invoices' => [self::SA, self::ACC, self::STU],
            'GET invoices/{invoice}' => [self::SA, self::ACC, self::STU],
            'POST invoices/{invoice}/pay' => [self::STU],
            'POST invoices' => [self::SA, self::ACC],
            'PATCH invoices/{invoice}' => [self::SA, self::ACC],
            'POST invoices/{invoice}/cash' => [self::SA, self::ACC],

            // Lookups
            'GET semesters' => self::ALL,
            'GET departments' => self::ALL,
            'GET programs' => self::ALL,
        ];

        // University administration (registrar + super admin)
        foreach (['users' => 'user', 'courses' => 'course', 'sections' => 'section'] as $plural => $one) {
            $m["GET admin/{$plural}"] = self::ADMIN;
        }
        foreach (['users' => 'user', 'departments' => 'department', 'programs' => 'program',
            'semesters' => 'semester', 'courses' => 'course', 'sections' => 'section'] as $plural => $one) {
            $m["POST admin/{$plural}"] = self::ADMIN;
            $m["GET admin/{$plural}/{{$one}}"] = self::ADMIN;
            $m["PUT admin/{$plural}/{{$one}}"] = self::ADMIN;
            $m["PATCH admin/{$plural}/{{$one}}"] = self::ADMIN;
            $m["DELETE admin/{$plural}/{{$one}}"] = self::ADMIN;
        }

        return $m;
    }

    public static function routes(): array
    {
        return collect(self::matrix())
            ->mapWithKeys(fn ($roles, $route) => [$route => [$route, $roles]])
            ->all();
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::SA, self::REG, self::HEAD, self::TEA, self::ACC, self::STU] as $role) {
            $this->users[$role] = User::factory()->create(['role' => $role]);
        }
        $teacher = $this->users[self::TEA];
        $student = $this->users[self::STU];

        $dept = Department::create(['name' => 'CSE', 'code' => 'CSE', 'head_id' => $this->users[self::HEAD]->id]);
        $program = Program::create(['department_id' => $dept->id, 'name' => 'BSc CSE', 'code' => 'BSCSE']);
        $course = Course::create(['program_id' => $program->id, 'code' => 'CSE 101', 'title' => 'Intro', 'credit' => 3]);
        $semester = Semester::create([
            'name' => 'Fall 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31',
            'is_current' => true, 'registration_open' => true,
        ]);
        $student->student()->create([
            'program_id' => $program->id, 'advisor_id' => $teacher->id, 'student_id' => '2022-1-60-001', 'batch' => '2022',
        ]);

        $section = CourseSection::create([
            'course_id' => $course->id, 'semester_id' => $semester->id, 'teacher_id' => $teacher->id, 'section' => 'A',
        ]);
        $registration = Registration::create([
            'course_section_id' => $section->id, 'student_id' => $student->id, 'status' => Registration::APPROVED,
        ]);

        $post = Post::create(['course_section_id' => $section->id, 'author_id' => $teacher->id, 'body' => 'Welcome']);
        $material = Material::create([
            'course_section_id' => $section->id, 'author_id' => $teacher->id, 'title' => 'Outline', 'type' => 'link', 'url' => 'https://example.com',
        ]);
        $assignment = Assignment::create([
            'course_section_id' => $section->id, 'author_id' => $teacher->id, 'title' => 'Lab 1', 'max_marks' => 10, 'published_at' => now(),
        ]);
        $submission = Submission::create([
            'assignment_id' => $assignment->id, 'student_id' => $student->id, 'content' => 'Answer', 'submitted_at' => now(),
        ]);
        $quiz = Quiz::create([
            'course_section_id' => $section->id, 'author_id' => $teacher->id, 'title' => 'Quiz 1', 'duration_minutes' => 10, 'published_at' => now(),
        ]);
        $quiz->questions()->create(['type' => 'mcq', 'question' => '2+2?', 'options' => ['3', '4'], 'correct_option' => 1, 'marks' => 1]);
        $attempt = QuizAttempt::create(['quiz_id' => $quiz->id, 'student_id' => $student->id, 'started_at' => now()]);
        $session = AttendanceSession::create([
            'course_section_id' => $section->id, 'created_by' => $teacher->id, 'date' => now()->toDateString(),
            'qr_token' => Str::random(40), 'qr_expires_at' => now()->addMinutes(10),
        ]);
        $invoice = Invoice::create(['student_id' => $student->id, 'semester_id' => $semester->id, 'title' => 'Tuition', 'amount' => 1000]);
        $notice = Notice::create([
            'author_id' => $this->users[self::REG]->id, 'title' => 'Hello', 'body' => 'All', 'audience' => 'all', 'published_at' => now(),
        ]);
        $complaint = Complaint::create(['user_id' => $student->id, 'category' => 'it', 'subject' => 'Wi-Fi', 'description' => 'Down']);
        $other = User::factory()->create();

        $this->ids = [
            'section' => $section->id, 'post' => $post->id, 'material' => $material->id, 'assignment' => $assignment->id,
            'submission' => $submission->id, 'quiz' => $quiz->id, 'attempt' => $attempt->id, 'session' => $session->id,
            'registration' => $registration->id, 'invoice' => $invoice->id, 'notice' => $notice->id,
            'complaint' => $complaint->id, 'user' => $other->id, 'department' => $dept->id, 'program' => $program->id,
            'semester' => $semester->id, 'course' => $course->id,
        ];

        // Some routes need a body to reach their authorization check.
        $this->ids['token'] = $session->qr_token;
    }

    #[DataProvider('routes')]
    public function test_route_permissions(string $route, array $allowed): void
    {
        [$method, $template] = explode(' ', $route, 2);
        $uri = '/api/'.preg_replace_callback('/\{(\w+)\}/', fn ($m) => $this->ids[$m[1]], $template);
        $body = $template === 'attendance/check-in' ? ['token' => $this->ids['token']] : [];

        // Guests are always refused.
        $this->call($method, $uri, $body, [], [], ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();

        foreach ($this->users as $role => $user) {
            DB::beginTransaction(); // undo whatever this request changes

            $this->app['auth']->forgetGuards();
            $status = $this->actingAs($user)
                ->call($method, $uri, $body, [], [], ['HTTP_ACCEPT' => 'application/json'])
                ->getStatusCode();

            DB::rollBack();

            if (in_array($role, $allowed, true)) {
                $this->assertNotContains($status, [401, 403], "{$role} should be ALLOWED on {$route} (got {$status}).");
                $this->assertLessThan(500, $status, "{$role} caused a server error on {$route}.");
            } else {
                $this->assertSame(403, $status, "{$role} should be DENIED on {$route} (got {$status}).");
            }
        }
    }

    /** Every authenticated route must appear in the matrix, so new routes can't skip this test. */
    public function test_matrix_covers_every_authenticated_route(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.substr($route->uri(), 4);
                if (! array_key_exists($key, self::matrix())) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertSame([], $missing, 'Add these routes to PermissionMatrixTest::matrix().');
    }

    public function test_deactivated_users_are_refused_everywhere(): void
    {
        $user = $this->users[self::STU];
        $user->update(['is_active' => false]);

        $this->actingAs($user)->getJson('/api/auth/me')->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->actingAs($user)->getJson('/api/dashboard')->assertForbidden();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
    }
}
