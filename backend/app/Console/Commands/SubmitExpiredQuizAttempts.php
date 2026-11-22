<?php

namespace App\Console\Commands;

use App\Models\QuizAttempt;
use App\Services\QuizGrader;
use Illuminate\Console\Command;

/**
 * A student who closes the tab mid-quiz still gets submitted: once the
 * deadline (plus grace) passes, their last autosave is graded.
 */
class SubmitExpiredQuizAttempts extends Command
{
    protected $signature = 'unisphere:submit-expired-quiz-attempts';

    protected $description = 'Submit quiz attempts whose time has run out, using the last saved answers';

    public function handle(QuizGrader $grader): int
    {
        $count = 0;

        QuizAttempt::whereNull('submitted_at')->with('quiz')->each(function (QuizAttempt $attempt) use ($grader, &$count) {
            if ($attempt->isExpired(QuizAttempt::GRACE_SECONDS)) {
                $attempt->finalize($grader, auto: true);
                $count++;
            }
        });

        $this->info("{$count} expired attempts submitted.");

        return self::SUCCESS;
    }
}
