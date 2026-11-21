<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'phone', 'role', 'avatar', 'is_active', 'password',
        'must_change_password', 'password_changed_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $appends = ['avatar_url'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'email_verified_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
    }

    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class)->latest('created_at');
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function faculty(): HasOne
    {
        return $this->hasOne(FacultyMember::class);
    }

    /** Sections this user teaches. */
    public function teachingSections(): HasMany
    {
        return $this->hasMany(CourseSection::class, 'teacher_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'student_id');
    }

    /** Sections this user is enrolled in (approved registrations only). */
    public function enrolledSections(): BelongsToMany
    {
        return $this->belongsToMany(CourseSection::class, 'registrations', 'student_id', 'course_section_id')
            ->wherePivot('status', Registration::APPROVED)
            ->withTimestamps();
    }

    public function hasRole(Role|string ...$roles): bool
    {
        $values = array_map(fn ($r) => $r instanceof Role ? $r->value : $r, $roles);

        return in_array($this->role->value, $values, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(...Role::admins());
    }

    public function isStudent(): bool
    {
        return $this->role === Role::Student;
    }
}
