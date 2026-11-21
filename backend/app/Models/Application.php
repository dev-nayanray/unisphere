<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Application extends Model
{
    public const STATUSES = ['submitted', 'under_review', 'accepted', 'rejected'];

    protected $fillable = [
        'tracking_code', 'program_id', 'name', 'email', 'phone', 'date_of_birth', 'gender', 'address',
        'ssc_gpa', 'ssc_year', 'hsc_gpa', 'hsc_year', 'documents', 'status', 'review_note',
        'reviewed_by', 'reviewed_at', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'ssc_gpa' => 'decimal:2',
            'hsc_gpa' => 'decimal:2',
            'documents' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Application $application) {
            do {
                $code = 'APP'.Str::upper(Str::random(7));
            } while (static::where('tracking_code', $code)->exists());

            $application->tracking_code ??= $code;
        });
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDecided(): bool
    {
        return in_array($this->status, ['accepted', 'rejected'], true);
    }
}
