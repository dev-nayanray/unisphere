<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Department;
use App\Models\Post;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\User;
use App\Notifications\NewStreamPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StreamTest extends TestCase
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

    private function url(string $path = ''): string
    {
        return "/api/classes/{$this->section->id}{$path}";
    }

    public function test_teacher_post_with_attachments_notifies_students(): void
    {
        Storage::fake('local');
        Notification::fake();

        $res = $this->actingAs($this->teacher)->post($this->url('/posts'), [
            'body' => 'Slides for week 1 <script>alert(1)</script>',
            'attachments' => [
                UploadedFile::fake()->image('slide.png'),
                UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('Slides for week 1 alert(1)', $res->json('body')); // tags stripped
        $this->assertCount(2, $res->json('attachments'));
        $this->assertTrue($res->json('attachments.0.is_image'));
        Notification::assertSentTo($this->student, NewStreamPost::class);

        // The signed link works without a login; a tampered one doesn't.
        $signed = $res->json('attachments.0.url');
        $this->get($signed)->assertOk();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $signed))->assertForbidden();
    }

    public function test_more_than_five_attachments_is_refused(): void
    {
        Storage::fake('local');
        $files = array_map(fn ($i) => UploadedFile::fake()->create("f{$i}.pdf", 10), range(1, 6));

        $this->actingAs($this->teacher)
            ->post($this->url('/posts'), ['body' => 'Too many', 'attachments' => $files], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('attachments');
    }

    public function test_scheduled_posts_appear_on_time_and_notify_then(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-01 08:00:00');

        $this->actingAs($this->teacher)
            ->postJson($this->url('/posts'), ['body' => 'Quiz tomorrow', 'scheduled_at' => '2026-10-01 18:00:00'])
            ->assertCreated();

        $this->actingAs($this->student)->getJson($this->url('/posts'))->assertJsonCount(0, 'data');
        $this->actingAs($this->teacher)->getJson($this->url('/posts'))->assertJsonCount(1, 'data');
        Notification::assertNothingSent();

        Carbon::setTestNow('2026-10-01 18:01:00');
        $this->artisan('unisphere:publish-scheduled-posts')->assertSuccessful();

        $this->actingAs($this->student)->getJson($this->url('/posts'))->assertJsonCount(1, 'data');
        Notification::assertSentTo($this->student, NewStreamPost::class);

        Carbon::setTestNow();
    }

    public function test_authors_edit_and_teachers_moderate(): void
    {
        $postId = $this->actingAs($this->student)->postJson($this->url('/posts'), ['body' => 'Hello class'])->json('id');

        $this->actingAs($this->student)->patchJson("/api/posts/{$postId}", ['body' => 'Hello everyone'])
            ->assertOk()
            ->assertJsonPath('body', 'Hello everyone');
        $this->assertNotNull(Post::find($postId)->edited_at);

        // The teacher can't rewrite a student's words, but can remove the post.
        $this->actingAs($this->teacher)->patchJson("/api/posts/{$postId}", ['body' => 'Changed'])->assertForbidden();
        $this->actingAs($this->teacher)->deleteJson("/api/posts/{$postId}")->assertOk();
    }

    public function test_comment_edit_and_moderation(): void
    {
        $post = Post::create(['course_section_id' => $this->section->id, 'author_id' => $this->teacher->id, 'body' => 'Hi', 'published_at' => now()]);
        $commentId = $this->actingAs($this->student)->postJson("/api/posts/{$post->id}/comments", ['body' => 'Thanks'])->json('id');

        $this->actingAs($this->student)->patchJson("/api/comments/{$commentId}", ['body' => 'Thank you'])->assertOk();
        $this->actingAs($this->teacher)->patchJson("/api/comments/{$commentId}", ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->teacher)->deleteJson("/api/comments/{$commentId}")->assertOk();
    }

    public function test_class_stream_permissions(): void
    {
        $post = Post::create(['course_section_id' => $this->section->id, 'author_id' => $this->teacher->id, 'body' => 'Hi', 'published_at' => now()]);

        $this->actingAs($this->teacher)->putJson($this->url('/stream-settings'), ['stream_permission' => 'comment'])->assertOk();
        $this->actingAs($this->student)->postJson($this->url('/posts'), ['body' => 'Can I post?'])->assertForbidden();
        $this->actingAs($this->student)->postJson("/api/posts/{$post->id}/comments", ['body' => 'Only comments'])->assertCreated();

        $this->actingAs($this->teacher)->putJson($this->url('/stream-settings'), ['stream_permission' => 'none'])->assertOk();
        $this->actingAs($this->student)->postJson("/api/posts/{$post->id}/comments", ['body' => 'Nope'])->assertForbidden();

        // Students can't change the setting.
        $this->actingAs($this->student)->putJson($this->url('/stream-settings'), ['stream_permission' => 'post_comment'])->assertForbidden();
    }

    public function test_publishing_classwork_posts_to_the_stream(): void
    {
        $this->actingAs($this->teacher)->postJson($this->url('/assignments'), ['title' => 'Lab 2', 'due_at' => now()->addDays(3)])->assertCreated();

        $this->actingAs($this->student)->getJson($this->url('/posts'))
            ->assertJsonPath('data.0.type', 'classwork')
            ->assertJsonPath('data.0.link.kind', 'assignment');

        $this->actingAs($this->student)->getJson($this->url('/upcoming'))
            ->assertOk()
            ->assertJsonPath('0.title', 'Lab 2');
    }

    public function test_only_teachers_pin(): void
    {
        $postId = $this->actingAs($this->student)->postJson($this->url('/posts'), ['body' => 'Pin me', 'is_pinned' => true])->json('id');
        $this->assertFalse(Post::find($postId)->is_pinned);

        $this->actingAs($this->student)->patchJson("/api/posts/{$postId}", ['is_pinned' => true])->assertForbidden();
        $this->actingAs($this->teacher)->patchJson("/api/posts/{$postId}", ['is_pinned' => true])->assertOk();
        $this->assertTrue(Post::find($postId)->is_pinned);
    }
}
