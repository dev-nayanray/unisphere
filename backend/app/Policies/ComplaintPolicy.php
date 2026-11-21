<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

class ComplaintPolicy
{
    public function view(User $user, Complaint $complaint): bool
    {
        return $user->isAdmin() || $complaint->user_id === $user->id || $complaint->assigned_to === $user->id;
    }

    /** Admins assign and resolve; the assignee can update the status. */
    public function update(User $user, Complaint $complaint): bool
    {
        return $user->isAdmin() || $complaint->assigned_to === $user->id;
    }
}
