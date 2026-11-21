<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'student_id', 'semester_id', 'title', 'amount', 'waiver', 'paid_amount', 'due_date', 'status',
    ];

    protected $appends = ['due_amount'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'waiver' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getDueAmountAttribute(): float
    {
        return max(0, round((float) $this->amount - (float) $this->waiver - (float) $this->paid_amount, 2));
    }

    /** Re-total successful payments and update the status. */
    public function refreshStatus(): void
    {
        $paid = (float) $this->payments()->where('status', 'success')->sum('amount');
        $this->paid_amount = $paid;
        $this->status = $this->due_amount <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
        $this->save();
    }
}
