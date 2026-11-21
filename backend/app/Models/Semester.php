<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Semester extends Model
{
    protected $fillable = ['name', 'start_date', 'end_date', 'registration_open', 'registration_deadline', 'is_current'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'registration_open' => 'boolean',
            'registration_deadline' => 'date:Y-m-d',
            'is_current' => 'boolean',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    /** Registration is open and the add/drop deadline (if any) has not passed. */
    public function acceptsRegistration(): bool
    {
        return $this->registration_open
            && ($this->registration_deadline === null || today()->lte($this->registration_deadline));
    }

    /** Only one semester can be current at a time. */
    public function makeCurrent(): void
    {
        static::query()->where('id', '!=', $this->id)->update(['is_current' => false]);
        $this->update(['is_current' => true]);
    }
}
