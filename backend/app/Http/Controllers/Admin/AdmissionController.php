<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AdmissionDecision;
use App\Services\StudentIdGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Registrar review of online applications. */
class AdmissionController extends Controller
{
    use ListsRecords;

    public function index(Request $request): JsonResponse
    {
        $request->mergeIfMissing(['page' => 1]);

        return $this->listing(
            $request,
            Application::with('program:id,code')
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->program_id, fn ($q, $id) => $q->where('program_id', $id)),
            searchable: ['name', 'email', 'tracking_code', 'phone'],
            sortable: ['created_at', 'name', 'hsc_gpa', 'ssc_gpa'],
            defaultSort: 'created_at',
        );
    }

    public function show(Application $application): JsonResponse
    {
        return response()->json($application->load(['program.department:id,code', 'reviewer:id,name', 'user.student:id,user_id,student_id']));
    }

    public function document(Application $application, int $index): StreamedResponse
    {
        $doc = $application->documents[$index] ?? abort(404);

        return Storage::disk('local')->download($doc['path'], $doc['original_name']);
    }

    /** Move to review, accept (creates the student account) or reject. */
    public function decide(Request $request, Application $application, StudentIdGenerator $ids): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['under_review', 'accepted', 'rejected'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_if($application->isDecided(), 422, 'This application has already been '.$application->status.'.');

        if ($data['status'] === 'accepted' && User::where('email', $application->email)->exists()) {
            return response()->json(['message' => 'An account with this email already exists.'], 422);
        }

        $result = DB::transaction(function () use ($application, $data, $request, $ids) {
            $extra = [];

            if ($data['status'] === 'accepted') {
                $password = Str::password(10, symbols: false);
                $user = User::create([
                    'name' => $application->name,
                    'email' => strtolower($application->email),
                    'phone' => $application->phone,
                    'role' => Role::Student->value,
                    'password' => $password,
                    'must_change_password' => true,
                ]);
                $student = Student::create([
                    'user_id' => $user->id,
                    'program_id' => $application->program_id,
                    'student_id' => $ids->next($application->program),
                    'batch' => now()->format('Y'),
                    'admission_date' => now()->toDateString(),
                ]);
                $application->user_id = $user->id;
                $extra = ['student_id' => $student->student_id, 'temporary_password' => $password];
            }

            $application->fill([
                'status' => $data['status'],
                'review_note' => $data['note'] ?? $application->review_note,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            return $extra;
        });

        if ($application->isDecided()) {
            Notification::route('mail', $application->email)
                ->notify(new AdmissionDecision($application, $result['student_id'] ?? null, $result['temporary_password'] ?? null));
        }

        return response()->json([
            'message' => match ($data['status']) {
                'accepted' => "Accepted. Student ID {$result['student_id']} created and login details emailed.",
                'rejected' => 'Application rejected and the applicant notified.',
                default => 'Marked as under review.',
            },
            'application' => $application->fresh(['program:id,code', 'reviewer:id,name']),
            ...$result,
        ]);
    }
}
