<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Student extends Model
{
    protected $fillable = [
        'user_id', 'program_id', 'advisor_id', 'student_id', 'batch', 'admission_date', 'cgpa', 'status',
    ];

    protected function casts(): array
    {
        return [
            'admission_date' => 'date:Y-m-d',
            'cgpa' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    /** CGPA = sum(grade point x credit) / sum(credit) over published results. */
    public function recalculateCgpa(): float
    {
        $rows = Result::query()
            ->whereNotNull('published_at')
            ->whereHas('registration', fn ($q) => $q->where('student_id', $this->user_id))
            ->with('registration.section.course')
            ->get();

        $credits = $rows->sum(fn (Result $r) => (float) $r->registration->section->course->credit);
        $points = $rows->sum(fn (Result $r) => (float) $r->grade_point * (float) $r->registration->section->course->credit);

        $cgpa = $credits > 0 ? round($points / $credits, 2) : 0.0;
        $this->update(['cgpa' => $cgpa]);

        return $cgpa;
    }
}
