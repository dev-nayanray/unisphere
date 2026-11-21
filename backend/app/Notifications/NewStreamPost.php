<?php

namespace App\Notifications;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** In-app notification for a new teacher announcement in a class. */
class NewStreamPost extends Notification
{
    use Queueable;

    public function __construct(public Post $post)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->post->loadMissing(['section.course:id,code', 'author:id,name']);

        return [
            'type' => 'announcement',
            'title' => "{$this->post->section->course->code}: {$this->post->author->name} posted \"".Str::limit($this->post->body, 60).'"',
            'section_id' => $this->post->course_section_id,
            'post_id' => $this->post->id,
        ];
    }
}
