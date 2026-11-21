<?php

namespace App\Policies;

use App\Models\AttendanceSession;
use App\Models\User;

class AttendanceSessionPolicy
{
    public function view(User $user, AttendanceSession $session): bool
    {
        return $session->section->canManage($user);
    }

    public function update(User $user, AttendanceSession $session): bool
    {
        return $this->view($user, $session);
    }
}
