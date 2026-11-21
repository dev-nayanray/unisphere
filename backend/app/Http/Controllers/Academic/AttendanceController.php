<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\CourseSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function index(CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);

        return response()->json(
            $section->attendanceSessions()
                ->withCount([
                    'records as present_count' => fn ($q) => $q->whereIn('status', ['present', 'late']),
                    'records',
                ])
                ->latest('date')
                ->get()
        );
    }

    /** Create a class session. Optionally mark everyone at once, or open a QR check-in. */
    public function store(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'topic' => ['nullable', 'string', 'max:200'],
            'with_qr' => ['sometimes', 'boolean'],
            'records' => ['sometimes', 'array'],
            'records.*.student_id' => ['required', 'exists:users,id'],
            'records.*.status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
        ]);

        $session = DB::transaction(function () use ($data, $section, $request) {
            $session = $section->attendanceSessions()->create([
                'date' => $data['date'],
                'topic' => $data['topic'] ?? null,
                'created_by' => $request->user()->id,
                'qr_token' => ($data['with_qr'] ?? false) ? Str::random(40) : null,
                'qr_expires_at' => ($data['with_qr'] ?? false)
                    ? now()->addMinutes(config('unisphere.qr_attendance_ttl_minutes'))
                    : null,
            ]);

            $this->saveRecords($session, $data['records'] ?? []);

            return $session;
        });

        return response()->json([
            ...$session->toArray(),
            // The front end renders this token as a QR code on the projector.
            'qr_token' => $session->qr_token,
        ], 201);
    }

    public function show(AttendanceSession $session): JsonResponse
    {
        Gate::authorize('view', $session);

        return response()->json([
            'session' => $session,
            'roster' => $session->section->students()->get(['users.id', 'users.name']),
            'records' => $session->records()->pluck('status', 'student_id'),
        ]);
    }

    public function mark(Request $request, AttendanceSession $session): JsonResponse
    {
        Gate::authorize('update', $session);

        $data = $request->validate([
            'records' => ['required', 'array'],
            'records.*.student_id' => ['required', 'exists:users,id'],
            'records.*.status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
        ]);

        $this->saveRecords($session, $data['records']);

        return response()->json(['message' => 'Attendance saved.']);
    }

    /** Student scans the QR code shown in class. */
    public function checkIn(Request $request): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string']])['token'];
        $session = AttendanceSession::where('qr_token', $token)->firstOrFail();

        abort_unless($session->qrIsValid(), 422, 'This QR code has expired.');
        Gate::authorize('attend', $session->section);

        AttendanceRecord::updateOrCreate(
            ['attendance_session_id' => $session->id, 'student_id' => $request->user()->id],
            ['status' => 'present']
        );

        return response()->json(['message' => 'Checked in. You are marked present.']);
    }

    /** Per-student attendance percentage for a section, flagging those below the minimum. */
    public function report(CourseSection $section): JsonResponse
    {
        $this->ensureCanView($section);
        $user = request()->user();

        $total = $section->attendanceSessions()->count();
        $attended = AttendanceRecord::query()
            ->whereIn('attendance_session_id', $section->attendanceSessions()->select('id'))
            ->whereIn('status', ['present', 'late'])
            ->selectRaw('student_id, count(*) as c')
            ->groupBy('student_id')
            ->pluck('c', 'student_id');

        $students = $section->students()
            ->when(Gate::denies('manage', $section), fn ($q) => $q->where('users.id', $user->id))
            ->get(['users.id', 'users.name']);

        $min = config('unisphere.min_attendance_percent');

        return response()->json([
            'total_sessions' => $total,
            'minimum_percent' => $min,
            'students' => $students->map(function ($s) use ($attended, $total, $min) {
                $percent = $total ? round(($attended[$s->id] ?? 0) / $total * 100, 1) : 100.0;

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'attended' => (int) ($attended[$s->id] ?? 0),
                    'percent' => $percent,
                    'warning' => $percent < $min,
                ];
            }),
        ]);
    }

    private function saveRecords(AttendanceSession $session, array $records): void
    {
        foreach ($records as $record) {
            AttendanceRecord::updateOrCreate(
                ['attendance_session_id' => $session->id, 'student_id' => $record['student_id']],
                ['status' => $record['status']]
            );
        }
    }
}
