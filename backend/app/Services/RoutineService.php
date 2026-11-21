<?php

namespace App\Services;

use App\Models\CourseSection;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\User;
use App\Support\Schedule;
use Illuminate\Support\Collection;

/** Weekly class routines for students, teachers and rooms, plus .ics export. */
class RoutineService
{
    /** Sections in the user's routine this semester. */
    public function sectionsFor(User $user, Semester $semester): Collection
    {
        $query = CourseSection::query()
            ->where('semester_id', $semester->id)
            ->with(['course:id,code,title,credit', 'teacher:id,name']);

        if ($user->isStudent()) {
            // Pending ones are shown too, so students can plan before approval.
            $query->whereHas('registrations', fn ($q) => $q->where('student_id', $user->id)->holdingSeat());
        } else {
            $query->where('teacher_id', $user->id);
        }

        return $query->get();
    }

    public function sectionsInRoom(string $room, Semester $semester): Collection
    {
        return CourseSection::where('semester_id', $semester->id)
            ->where('room', $room)
            ->with(['course:id,code,title,credit', 'teacher:id,name'])
            ->get();
    }

    /** Flatten sections into one row per weekly class slot, sorted by day and time. */
    public function slots(Collection $sections, ?User $student = null): Collection
    {
        $statuses = $student
            ? Registration::where('student_id', $student->id)->whereIn('course_section_id', $sections->pluck('id'))->pluck('status', 'course_section_id')
            : collect();

        return $sections
            ->flatMap(fn (CourseSection $s) => collect($s->schedule ?? [])->map(fn ($slot) => [
                'section_id' => $s->id,
                'code' => $s->course->code,
                'title' => $s->course->title,
                'section' => $s->section,
                'room' => $s->room,
                'teacher' => $s->teacher?->name,
                'color' => $s->cover_color,
                'day' => $slot['day'],
                'start' => $slot['start'],
                'end' => $slot['end'],
                'status' => $statuses[$s->id] ?? null,
            ]))
            ->sortBy(fn ($slot) => array_search($slot['day'], Schedule::DAYS, true).$slot['start'])
            ->values();
    }

    public function today(User $user): Collection
    {
        $semester = Semester::current()->first();
        if (! $semester) {
            return collect();
        }

        $day = Schedule::dayName(now());

        return $this->slots($this->sectionsFor($user, $semester), $user->isStudent() ? $user : null)
            ->where('day', $day)
            ->values();
    }

    /** iCalendar file with one weekly repeating event per class slot, for the whole semester. */
    public function ics(Collection $slots, Semester $semester, string $calendarName): string
    {
        $until = $semester->end_date->copy()->endOfDay()->utc()->format('Ymd\THis\Z');
        $stamp = now()->utc()->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//UniSphere//Class Routine//EN',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:'.$this->escape($calendarName),
            'X-WR-TIMEZONE:Asia/Dhaka',
            'BEGIN:VTIMEZONE',
            'TZID:Asia/Dhaka',
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:+0600',
            'TZOFFSETTO:+0600',
            'TZNAME:BST',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];

        foreach ($slots as $i => $slot) {
            $first = Schedule::firstOccurrence($slot['day'], $semester->start_date);
            $start = $first->format('Ymd').'T'.str_replace(':', '', $slot['start']).'00';
            $end = $first->format('Ymd').'T'.str_replace(':', '', $slot['end']).'00';

            array_push($lines,
                'BEGIN:VEVENT',
                "UID:unisphere-{$semester->id}-{$slot['section_id']}-{$i}@unisphere",
                "DTSTAMP:{$stamp}",
                "DTSTART;TZID=Asia/Dhaka:{$start}",
                "DTEND;TZID=Asia/Dhaka:{$end}",
                "RRULE:FREQ=WEEKLY;UNTIL={$until}",
                'SUMMARY:'.$this->escape("{$slot['code']} {$slot['title']} ({$slot['section']})"),
                'LOCATION:'.$this->escape($slot['room'] ? "Room {$slot['room']}" : 'TBA'),
                'DESCRIPTION:'.$this->escape('Teacher: '.($slot['teacher'] ?? 'TBA')),
                'END:VEVENT',
            );
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    }
}
