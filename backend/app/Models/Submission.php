<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

class Submission extends Model
{
    protected $fillable = [
        'assignment_id', 'student_id', 'content', 'file_path', 'file_name', 'file_mime', 'file_disk',
        'submitted_at', 'is_late', 'marks', 'feedback', 'graded_at', 'graded_by', 'rubric_scores', 'returned_at',
    ];

    protected $hidden = ['file_path', 'file_disk'];

    protected $appends = ['file_url'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'returned_at' => 'datetime',
            'is_late' => 'boolean',
            'marks' => 'decimal:2',
            'rubric_scores' => 'array',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SubmissionVersion::class)->orderByDesc('number');
    }

    public function isTurnedIn(): bool
    {
        return $this->submitted_at !== null;
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }

    /** 60-minute signed link to the uploaded file, so it previews inline. */
    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path
            ? URL::temporarySignedRoute('submission-files.show', now()->addMinutes(60), ['submission' => $this->id])
            : null;
    }

    /**
     * What the student may see: marks, feedback and rubric scores stay hidden
     * until the teacher returns the work.
     */
    public function forStudent(): array
    {
        $data = $this->toArray();

        if (! $this->isReturned()) {
            $data['marks'] = null;
            $data['feedback'] = null;
            $data['rubric_scores'] = null;
            $data['graded_at'] = null;
        }

        return $data;
    }
}
