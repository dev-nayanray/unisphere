<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notice extends Model
{
    protected $fillable = [
        'author_id', 'department_id', 'title', 'body', 'audience', 'is_urgent', 'attachment_path', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_urgent' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Notices a given user is allowed to see. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $audience = $user->isStudent() ? 'students' : 'faculty';

        return $query->whereNotNull('published_at')
            ->whereIn('audience', ['all', $audience]);
    }
}
