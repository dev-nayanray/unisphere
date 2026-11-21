<?php

namespace App\Policies;

use App\Models\CourseSection;
use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    /** Teachers always; other members only if the class allows student posts. */
    public function create(User $user, CourseSection $section): bool
    {
        if ($section->canManage($user)) {
            return true;
        }

        return $section->canView($user) && $section->stream_permission === CourseSection::STREAM_POST_COMMENT;
    }

    /** Comment on a published post, unless the teacher turned comments off. */
    public function comment(User $user, Post $post): bool
    {
        $section = $post->section;

        if ($section->canManage($user)) {
            return true;
        }

        return $section->canView($user)
            && ! $post->isScheduled()
            && $section->stream_permission !== CourseSection::STREAM_NONE;
    }

    /** Only the author edits a post. */
    public function update(User $user, Post $post): bool
    {
        return $post->author_id === $user->id;
    }

    /** The author, or the class teacher (moderation). */
    public function delete(User $user, Post $post): bool
    {
        return $post->author_id === $user->id || $post->section->canManage($user);
    }

    /** Pin or unpin: the class teacher. */
    public function pin(User $user, Post $post): bool
    {
        return $post->section->canManage($user);
    }
}
