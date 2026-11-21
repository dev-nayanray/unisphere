<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    public const TYPE_ANNOUNCEMENT = 'announcement';
    public const TYPE_CLASSWORK = 'classwork';

    protected $fillable = [
        'course_section_id', 'author_id', 'body', 'is_pinned', 'type', 'link',
        'published_at', 'notified_at', 'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'link' => 'array',
            'published_at' => 'datetime',
            'notified_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PostAttachment::class);
    }

    /** Posts whose publish time has come. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isScheduled(): bool
    {
        return $this->published_at !== null && $this->published_at->isFuture();
    }

    /** Auto-post to the stream when classwork is published ("Rahim posted a new assignment: ..."). */
    public static function announceClasswork(CourseSection $section, User $author, string $kind, int $id, string $title): self
    {
        $label = ['assignment' => 'a new assignment', 'quiz' => 'a new quiz', 'material' => 'new material'][$kind] ?? 'new classwork';

        return static::create([
            'course_section_id' => $section->id,
            'author_id' => $author->id,
            'type' => self::TYPE_CLASSWORK,
            'link' => ['kind' => $kind, 'id' => $id],
            'body' => "{$author->name} posted {$label}: {$title}",
            'published_at' => now(),
            'notified_at' => now(), // classwork has its own notification
        ]);
    }
}
