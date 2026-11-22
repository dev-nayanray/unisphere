<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradebookOverride extends Model
{
    protected $fillable = ['course_section_id', 'student_id', 'total', 'note', 'set_by'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2'];
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
