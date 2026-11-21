<?php

namespace App\Notifications;

use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** "Your teacher returned Lab 2: 16/20" */
class GradesReturned extends Notification
{
    use Queueable;

    public function __construct(public Assignment $assignment, public Submission $submission)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $marks = $this->assignment->effectiveMarks($this->submission);

        return [
            'type' => 'grade',
            'title' => "Grade returned for {$this->assignment->title}: {$marks}/".(float) $this->assignment->max_marks,
            'assignment_id' => $this->assignment->id,
        ];
    }
}
