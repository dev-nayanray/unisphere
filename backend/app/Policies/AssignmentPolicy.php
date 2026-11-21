<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;

class AssignmentPolicy
{
    /** Members see published work; the teacher also sees drafts. */
    public function view(User $user, Assignment $assignment): bool
    {
        $section = $assignment->section;

        if ($section->canManage($user)) {
            return true;
        }

        return $assignment->published_at !== null && $section->canView($user);
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return $assignment->section->canManage($user);
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return $this->update($user, $assignment);
    }

    /** Record marks for the whole class (offline exams). */
    public function grade(User $user, Assignment $assignment): bool
    {
        return $this->update($user, $assignment);
    }

    /** Turn in work: enrolled students, published work only. */
    public function submit(User $user, Assignment $assignment): bool
    {
        return $assignment->published_at !== null && $assignment->section->isStudent($user);
    }
}
