<?php

namespace App\Notifications;

use App\Models\Assignment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app notification when a teacher posts new classwork.
 * Add 'mail' or an FCM channel to via() to send push / email as well.
 */
class NewClassworkPosted extends Notification
{
    use Queueable;

    public function __construct(public Assignment $assignment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'classwork',
            'title' => 'New '.$this->assignment->category.': '.$this->assignment->title,
            'section_id' => $this->assignment->course_section_id,
            'assignment_id' => $this->assignment->id,
            'due_at' => $this->assignment->due_at?->toIso8601String(),
        ];
    }
}
