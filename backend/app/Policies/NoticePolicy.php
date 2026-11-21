<?php

namespace App\Policies;

use App\Models\Notice;
use App\Models\User;

class NoticePolicy
{
    public function view(User $user, Notice $notice): bool
    {
        return Notice::visibleTo($user)->whereKey($notice->id)->exists();
    }

    public function delete(User $user, Notice $notice): bool
    {
        return $user->isAdmin() || $notice->author_id === $user->id;
    }
}
