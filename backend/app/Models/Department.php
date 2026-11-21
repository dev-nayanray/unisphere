<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['name', 'code', 'head_id'];

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_id');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function facultyMembers(): HasMany
    {
        return $this->hasMany(FacultyMember::class);
    }
}
