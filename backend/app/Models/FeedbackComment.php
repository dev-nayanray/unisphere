<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reusable feedback snippet in a teacher's comment bank. */
class FeedbackComment extends Model
{
    protected $fillable = ['teacher_id', 'text'];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
