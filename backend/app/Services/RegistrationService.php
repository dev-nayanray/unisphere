<?php

namespace App\Services;

use App\Models\CourseSection;
use App\Models\Invoice;
use App\Models\Registration;
use App\Models\Result;
use App\Models\Semester;
use App\Models\User;
use App\Notifications\RegistrationUpdated;
use App\Support\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every academic rule for course registration lives here, so the student
 * screen, the advisor screen and the tests all agree.
 */
class RegistrationService
{
    /**
     * Register a student for sections.
     *
     * @return array{errors: array<string, array<int, string>>, registered: Collection, waitlisted: Collection}
     */
    public function register(User $student, Semester $semester, Collection $sections): array
    {
        $errors = $this->check($student, $semester, $sections);
        if ($errors) {
            return ['errors' => $errors, 'registered' => collect(), 'waitlisted' => collect()];
        }

        $registered = collect();
        $waitlisted = collect();

        DB::transaction(function () use ($student, $sections, &$registered, &$waitlisted) {
            foreach ($sections as $section) {
                $full = $this->seatsTaken($section) >= $section->capacity;
                $retake = $this->bestGradePoint($student->id, $section->course_id) !== null;

                // Re-use a dropped/rejected row instead of creating a duplicate.
                $registration = Registration::updateOrCreate(
                    ['course_section_id' => $section->id, 'student_id' => $student->id],
                    [
                        'status' => $full ? Registration::WAITLISTED : Registration::PENDING,
                        'waitlisted_at' => $full ? now() : null,
                        'is_retake' => $retake,
                        'note' => null,
                        'approved_by' => null,
                        'decided_at' => null,
                    ]
                );

                $full ? $waitlisted->push($registration) : $registered->push($registration);
            }
        });

        return ['errors' => [], 'registered' => $registered, 'waitlisted' => $waitlisted];
    }

    /**
     * All rule checks. Errors are keyed "sections.{id}" for a specific course,
     * or "section_ids" for problems with the whole request.
     *
     * @return array<string, array<int, string>>
     */
    public function check(User $student, Semester $semester, Collection $sections): array
    {
        $errors = [];
        $add = function (string $key, string $message) use (&$errors) {
            $errors[$key][] = $message;
        };

        if (! $semester->acceptsRegistration()) {
            $add('section_ids', 'Course registration is closed for '.$semester->name.'.');

            return $errors;
        }

        if (config('unisphere.block_registration_on_overdue_fees') && $this->hasOverdueFees($student)) {
            $add('section_ids', 'You have overdue fees. Please pay them before registering.');
        }

        $current = $this->activeRegistrations($student, $semester);
        $currentCourseIds = $current->pluck('section.course_id');

        // Courses chosen twice in this request, or already taken this semester.
        foreach ($sections->groupBy('course_id') as $group) {
            if ($group->count() > 1) {
                $add('section_ids', $group->first()->course->code.': choose only one section.');
            }
        }

        $schedules = $current->map(fn ($r) => $r->section)->concat($sections);

        foreach ($sections as $section) {
            $key = "sections.{$section->id}";
            $code = $section->course->code;

            if ($section->semester_id !== $semester->id) {
                $add($key, "{$code}: not offered in {$semester->name}.");
            }

            if ($currentCourseIds->contains($section->course_id)) {
                $add($key, "{$code}: you are already registered for this course.");
            }

            if ($section->course->prerequisite_id && ! $this->hasPassed($student->id, $section->course->prerequisite_id)) {
                $add($key, "{$code}: prerequisite {$section->course->prerequisite?->code} not completed.");
            }

            $best = $this->bestGradePoint($student->id, $section->course_id);
            if ($best !== null && $best > config('unisphere.retake_max_grade_point')) {
                $add($key, "{$code}: already passed with grade point {$best}. Retakes are allowed only at C+ (2.50) or below.");
            }

            foreach ($schedules as $other) {
                if ($other->id !== $section->id && $this->clashes($section, $other)) {
                    $add($key, "{$code}: time clash with {$other->course->code}.");
                    break;
                }
            }
        }

        $credits = $current->sum(fn ($r) => (float) $r->section->course->credit)
            + $sections->sum(fn ($s) => (float) $s->course->credit);
        $max = config('unisphere.max_credits_per_semester');
        $min = config('unisphere.min_credits_per_semester');

        if ($credits > $max) {
            $add('section_ids', "That would be {$credits} credits; the maximum is {$max}.");
        }
        if ($credits < $min) {
            $add('section_ids', "That would be {$credits} credits; register for at least {$min}.");
        }

        return $errors;
    }

    /** Drop a course. Frees the seat for the next waitlisted student. */
    public function drop(Registration $registration): void
    {
        $holdsSeat = in_array($registration->status, Registration::SEAT_HOLDING, true);
        $registration->update(['status' => Registration::DROPPED, 'waitlisted_at' => null]);

        if ($holdsSeat) {
            $this->promoteWaitlist($registration->section);
        }
    }

    /** Advisor / head / registrar decision. */
    public function decide(Registration $registration, string $status, ?string $note, User $by): void
    {
        $registration->update([
            'status' => $status,
            'note' => $note,
            'approved_by' => $by->id,
            'decided_at' => now(),
        ]);

        $registration->student->notify(new RegistrationUpdated($registration));

        if ($status === Registration::REJECTED) {
            $this->promoteWaitlist($registration->section);
        }
    }

    /** Registrar override: enrol a student even if the section is full. */
    public function forceAdd(User $student, CourseSection $section, User $by): Registration
    {
        $registration = Registration::updateOrCreate(
            ['course_section_id' => $section->id, 'student_id' => $student->id],
            [
                'status' => Registration::APPROVED,
                'forced' => true,
                'approved_by' => $by->id,
                'decided_at' => now(),
                'waitlisted_at' => null,
                'note' => 'Added by the registrar.',
            ]
        );

        $student->notify(new RegistrationUpdated($registration));

        return $registration;
    }

    /** Move waitlisted students into free seats, first come first served. */
    public function promoteWaitlist(CourseSection $section): int
    {
        $promoted = 0;

        while ($this->seatsTaken($section) < $section->capacity) {
            $next = Registration::where('course_section_id', $section->id)
                ->where('status', Registration::WAITLISTED)
                ->orderBy('waitlisted_at')
                ->orderBy('id')
                ->first();

            if (! $next) {
                break;
            }

            $next->update(['status' => Registration::PENDING, 'waitlisted_at' => null, 'note' => 'A seat opened up. Waiting for advisor approval.']);
            $next->student->notify(new RegistrationUpdated($next));
            $promoted++;
        }

        return $promoted;
    }

    public function seatsTaken(CourseSection $section): int
    {
        return Registration::where('course_section_id', $section->id)->holdingSeat()->count();
    }

    public function waitlistPosition(Registration $registration): ?int
    {
        if ($registration->status !== Registration::WAITLISTED) {
            return null;
        }

        return Registration::where('course_section_id', $registration->course_section_id)
            ->where('status', Registration::WAITLISTED)
            ->where(fn ($q) => $q->where('waitlisted_at', '<', $registration->waitlisted_at)
                ->orWhere(fn ($q) => $q->where('waitlisted_at', $registration->waitlisted_at)->where('id', '<', $registration->id)))
            ->count() + 1;
    }

    /** Two sections clash if any of their weekly slots overlap on the same day. */
    public function clashes(CourseSection $a, CourseSection $b): bool
    {
        return Schedule::firstOverlap($a->schedule, $b->schedule) !== null;
    }

    private function activeRegistrations(User $student, Semester $semester): Collection
    {
        return Registration::where('student_id', $student->id)
            ->whereIn('status', [...Registration::SEAT_HOLDING, Registration::WAITLISTED])
            ->whereHas('section', fn ($q) => $q->where('semester_id', $semester->id))
            ->with('section.course')
            ->get();
    }

    private function hasOverdueFees(User $student): bool
    {
        return Invoice::where('student_id', $student->id)
            ->where('status', '!=', 'paid')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->exists();
    }

    private function hasPassed(int $studentId, int $courseId): bool
    {
        return ($this->bestGradePoint($studentId, $courseId) ?? 0) > 0;
    }

    /** Best published grade point the student has earned in a course, or null if never completed. */
    private function bestGradePoint(int $studentId, int $courseId): ?float
    {
        $best = Result::query()
            ->whereNotNull('published_at')
            ->whereHas('registration', fn ($q) => $q
                ->where('student_id', $studentId)
                ->whereHas('section', fn ($q) => $q->where('course_id', $courseId)))
            ->max('grade_point');

        return $best === null ? null : (float) $best;
    }
}
