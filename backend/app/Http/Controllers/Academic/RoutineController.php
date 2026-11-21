<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Semester;
use App\Services\RoutineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RoutineController extends Controller
{
    public function __construct(private readonly RoutineService $routines)
    {
    }

    /** The signed-in student's or teacher's weekly routine. */
    public function mine(Request $request): JsonResponse
    {
        $semester = Semester::current()->firstOrFail();
        $user = $request->user();
        $sections = $this->routines->sectionsFor($user, $semester);

        return response()->json([
            'semester' => $semester,
            'slots' => $this->routines->slots($sections, $user->isStudent() ? $user : null),
            'unscheduled' => $sections->filter(fn ($s) => empty($s->schedule))->map(fn ($s) => $s->course->code)->values(),
        ]);
    }

    /** Download the routine as an .ics calendar file (Google Calendar, Outlook, phone calendars). */
    public function calendar(Request $request): Response
    {
        $semester = Semester::current()->firstOrFail();
        $user = $request->user();
        $slots = $this->routines->slots($this->routines->sectionsFor($user, $semester), $user->isStudent() ? $user : null);

        return response($this->routines->ics($slots, $semester, "UniSphere {$semester->name}"), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="unisphere-routine.ics"',
        ]);
    }

    /** Rooms used this semester, for the room-routine picker. */
    public function rooms(): JsonResponse
    {
        $semester = Semester::current()->firstOrFail();

        return response()->json(
            CourseSection::where('semester_id', $semester->id)->whereNotNull('room')->distinct()->orderBy('room')->pluck('room')
        );
    }

    public function room(string $room): JsonResponse
    {
        $semester = Semester::current()->firstOrFail();

        return response()->json([
            'semester' => $semester,
            'room' => $room,
            'slots' => $this->routines->slots($this->routines->sectionsInRoom($room, $semester)),
        ]);
    }
}
