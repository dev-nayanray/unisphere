<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Result extends Model
{
    protected $fillable = [
        'registration_id', 'breakdown', 'total', 'letter_grade', 'grade_point', 'published_at', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'breakdown' => 'array',
            'total' => 'decimal:2',
            'grade_point' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
