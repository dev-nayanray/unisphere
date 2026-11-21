<?php

namespace App\Notifications;

use App\Models\Notice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NoticePublished extends Notification
{
    use Queueable;

    public function __construct(public Notice $notice)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'notice',
            'title' => ($this->notice->is_urgent ? '[Urgent] ' : '').$this->notice->title,
            'notice_id' => $this->notice->id,
        ];
    }
}
