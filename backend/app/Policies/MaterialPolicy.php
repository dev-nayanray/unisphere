<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\User;

class MaterialPolicy
{
    public function delete(User $user, Material $material): bool
    {
        return $material->section->canManage($user);
    }
}
