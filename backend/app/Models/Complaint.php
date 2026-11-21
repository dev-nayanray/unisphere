<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Complaint extends Model
{
    public const CATEGORIES = ['academic', 'transport', 'accounts', 'it', 'facilities', 'other'];
    public const STATUSES = ['pending', 'in_progress', 'resolved'];

    protected $fillable = [
        'user_id', 'assigned_to', 'category', 'subject', 'description', 'status', 'resolution', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
