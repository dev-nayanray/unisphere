<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One turn-in attempt. The submission row always holds the latest. */
class SubmissionVersion extends Model
{
    protected $fillable = ['submission_id', 'number', 'content', 'file_path', 'file_name', 'file_mime', 'submitted_at', 'is_late'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
