<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A course offered in a semester. Every section is also a virtual classroom
 * (stream, classwork, people, grades), similar to a Google Classroom class.
 */
class CourseSection extends Model
{
    protected $fillable = [
        'course_id', 'semester_id', 'teacher_id', 'section', 'room', 'capacity',
        'join_code', 'schedule', 'grading_scheme', 'cover_color', 'stream_permission',
    ];

    /** stream_permission values: what students may do in the class stream. */
    public const STREAM_POST_COMMENT = 'post_comment';
    public const STREAM_COMMENT = 'comment';
    public const STREAM_NONE = 'none';

    protected function casts(): array
    {
        return [
            'schedule' => 'array',
            'grading_scheme' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CourseSection $section) {
            $section->join_code ??= static::uniqueJoinCode();
        });
    }

    public static function uniqueJoinCode(): string
    {
        do {
            $code = Str::lower(Str::random(7));
        } while (static::query()->where('join_code', $code)->exists());

        return $code;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /** Students with an approved registration. */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'registrations', 'course_section_id', 'student_id')
            ->wherePivot('status', Registration::APPROVED)
            ->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    public function gradingScheme(): array
    {
        return $this->grading_scheme ?: config('unisphere.default_grading_scheme');
    }

    public function isTeacher(User $user): bool
    {
        return $this->teacher_id === $user->id;
    }

    public function isStudent(User $user): bool
    {
        return $this->registrations()
            ->where('student_id', $user->id)
            ->where('status', Registration::APPROVED)
            ->exists();
    }

    /** Teacher, enrolled student, the department head, or an admin. */
    public function canView(User $user): bool
    {
        if ($user->isAdmin() || $this->isTeacher($user)) {
            return true;
        }

        if ($user->hasRole('department_head')) {
            $this->loadMissing('course.program');

            return Department::query()
                ->whereKey($this->course->program->department_id)
                ->where('head_id', $user->id)
                ->exists();
        }

        return $this->isStudent($user);
    }

    /** Teacher of the class or an admin may manage classwork and grades. */
    public function canManage(User $user): bool
    {
        return $user->isAdmin() || $this->isTeacher($user);
    }
}
