<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/** Files a teacher attaches to an assignment (question paper, dataset, template). */
class AssignmentAttachment extends Model
{
    protected $fillable = ['assignment_id', 'path', 'original_name', 'mime', 'size'];

    protected $hidden = ['path'];

    protected $appends = ['url', 'is_image', 'is_pdf'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** 60-minute signed link, so previews work without the login token. */
    public function getUrlAttribute(): string
    {
        return URL::temporarySignedRoute('assignment-attachments.show', now()->addMinutes(60), ['attachment' => $this->id]);
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
