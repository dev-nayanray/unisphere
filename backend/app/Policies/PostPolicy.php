<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function comment(User $user, Post $post): bool
    {
        return $post->section->canView($user);
    }

    /** The author, or the class teacher (moderation). */
    public function update(User $user, Post $post): bool
    {
        return $post->author_id === $user->id || $post->section->canManage($user);
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->update($user, $post);
    }
}
