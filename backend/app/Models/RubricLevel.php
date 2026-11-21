<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RubricLevel extends Model
{
    protected $fillable = ['rubric_criterion_id', 'title', 'description', 'points', 'position'];

    protected function casts(): array
    {
        return ['points' => 'decimal:2'];
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'rubric_criterion_id');
    }
}
