<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RubricCriterion extends Model
{
    protected $table = 'rubric_criteria';

    protected $fillable = ['assignment_id', 'title', 'description', 'position'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(RubricLevel::class)->orderByDesc('points');
    }
}
