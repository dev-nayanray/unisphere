<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = ['program_id', 'prerequisite_id', 'code', 'title', 'credit', 'description'];

    protected function casts(): array
    {
        return ['credit' => 'decimal:1'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'prerequisite_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class);
    }
}
