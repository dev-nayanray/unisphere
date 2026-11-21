<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class PostAttachment extends Model
{
    protected $fillable = ['post_id', 'path', 'original_name', 'mime', 'size'];

    protected $hidden = ['path'];

    protected $appends = ['url', 'is_image', 'is_pdf'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Files are private. The API hands out a link signed for 60 minutes, so
     * <img> and <iframe> previews work without sending the login token.
     */
    public function getUrlAttribute(): string
    {
        return URL::temporarySignedRoute('post-attachments.show', now()->addMinutes(60), ['attachment' => $this->id]);
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function getIsPdfAttribute(): bool
    {
        return $this->mime === 'application/pdf';
    }
}
