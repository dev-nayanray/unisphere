<?php

namespace App\Console\Commands;

use App\Http\Controllers\Classroom\AssignmentController;
use App\Models\Assignment;
use App\Models\Post;
use App\Notifications\NewStreamPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Scheduled posts become visible on their own once published_at passes;
 * this command (run every minute by the scheduler) sends their notifications.
 */
class PublishScheduledPosts extends Command
{
    protected $signature = 'unisphere:publish-scheduled-posts';

    protected $description = 'Notify classes about scheduled posts and assignments whose time has come';

    public function handle(): int
    {
        $posts = Post::published()
            ->whereNull('notified_at')
            ->with('section')
            ->get();

        foreach ($posts as $post) {
            Notification::send($post->section->students()->get(), new NewStreamPost($post));
            $post->update(['notified_at' => now()]);
        }

        // Scheduled assignments: notify the assigned students and post to the stream.
        $assignments = Assignment::published()->whereNull('notified_at')->with(['section', 'author'])->get();
        foreach ($assignments as $assignment) {
            AssignmentController::announceIfDue($assignment);
        }

        $this->info("Published {$posts->count()} scheduled post(s) and {$assignments->count()} scheduled assignment(s).");

        return self::SUCCESS;
    }
}
