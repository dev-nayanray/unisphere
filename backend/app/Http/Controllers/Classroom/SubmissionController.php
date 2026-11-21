<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Student;
use App\Models\Submission;
use App\Notifications\GradesReturned;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class SubmissionController extends Controller
{
    /** A student turns in (or re-turns in) work. Every turn-in is kept as a version. */
    public function store(Request $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();
        Gate::authorize('submit', $assignment);
        abort_if($assignment->isPastDue() && ! $assignment->acceptsLateWork(), 422, 'The due date has passed and late work is not accepted.');

        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:20000', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:20480', 'required_without:content'],
        ]);

        $existing = Submission::where('assignment_id', $assignment->id)->where('student_id', $user->id)->first();
        abort_if($existing?->isReturned(), 422, 'This work has been graded and returned, so it can no longer be changed.');
        abort_if($existing?->isTurnedIn(), 422, 'You already turned this in. Unsubmit first to make changes.');

        $file = $request->file('file');
        $fileFields = $file
            ? [
                'file_path' => $file->store("classes/{$assignment->course_section_id}/submissions/{$assignment->id}", 'local'),
                'file_name' => $file->getClientOriginalName(),
                'file_mime' => $file->getMimeType() ?: 'application/octet-stream',
                'file_disk' => 'local',
            ]
            : [];

        $submission = DB::transaction(function () use ($assignment, $user, $data, $fileFields) {
            $submission = Submission::updateOrCreate(
                ['assignment_id' => $assignment->id, 'student_id' => $user->id],
                [
                    'content' => $data['content'] ?? null,
                    ...$fileFields,
                    'submitted_at' => now(),
                    'is_late' => $assignment->isPastDue(),
                ]
            );

            $submission->versions()->create([
                'number' => $submission->versions()->count() + 1,
                'content' => $submission->content,
                'file_path' => $submission->file_path,
                'file_name' => $submission->file_name,
                'file_mime' => $submission->file_mime,
                'submitted_at' => $submission->submitted_at,
                'is_late' => $submission->is_late,
            ]);

            return $submission;
        });

        $penalty = $assignment->latePenaltyPercent($submission);

        return response()->json([
            ...$submission->forStudent(),
            'late_penalty_percent' => $penalty,
            'message' => $penalty ? "Turned in late: {$penalty}% will be taken off your marks." : 'Turned in.',
        ], 201);
    }

    /** Take work back to change it. Allowed until the teacher returns the grade. */
    public function unsubmit(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('submit', $assignment);

        $submission = Submission::where('assignment_id', $assignment->id)->where('student_id', $request->user()->id)->firstOrFail();
        abort_if($submission->isReturned(), 422, 'This work has already been returned.');
        abort_unless($submission->isTurnedIn(), 422, 'This work is not turned in.');

        $submission->update(['submitted_at' => null]);

        return response()->json(['message' => 'Unsubmitted. Remember to turn it in again.', 'submission' => $submission->forStudent()]);
    }

    /** Grade (or regrade) a submission. Marks stay hidden from the student until returned. */
    public function grade(Request $request, Submission $submission): JsonResponse
    {
        $assignment = $submission->assignment;
        Gate::authorize('grade', $submission);

        $assignment->loadMissing('criteria.levels');
        $hasRubric = $assignment->criteria->isNotEmpty();

        $data = $request->validate([
            // With a rubric, marks are optional: they are the sum of the chosen levels.
            'marks' => [$hasRubric ? 'nullable' : 'required', 'numeric', 'min:0', 'max:'.$assignment->max_marks],
            'rubric_scores' => [$hasRubric && ! $request->filled('marks') ? 'required' : 'nullable', 'array'],
            'feedback' => ['nullable', 'string', 'max:5000'],
            'return' => ['sometimes', 'boolean'],
        ]);

        if ($hasRubric && ! empty($data['rubric_scores'])) {
            $data['marks'] = $this->rubricTotal($assignment, $data['rubric_scores']);
        }

        $submission->update([
            'marks' => $data['marks'],
            'rubric_scores' => $data['rubric_scores'] ?? null,
            'feedback' => array_key_exists('feedback', $data) ? $data['feedback'] : $submission->feedback,
            'graded_at' => now(),
            'graded_by' => $request->user()->id,
        ]);

        if ($request->boolean('return')) {
            $this->returnSubmissions($assignment, collect([$submission]));
        }

        return response()->json($this->forTeacher($assignment, $submission->fresh()));
    }

    /** Record marks for work done on paper (mid/final exams). */
    public function gradeOffline(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('grade', $assignment);

        $data = $request->validate([
            'grades' => ['required', 'array'],
            'grades.*.student_id' => ['required', 'exists:users,id'],
            'grades.*.marks' => ['required', 'numeric', 'min:0', 'max:'.$assignment->max_marks],
            'grades.*.feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        foreach ($data['grades'] as $row) {
            Submission::updateOrCreate(
                ['assignment_id' => $assignment->id, 'student_id' => $row['student_id']],
                [
                    'marks' => $row['marks'],
                    'feedback' => $row['feedback'] ?? null,
                    'graded_at' => now(),
                    'graded_by' => $request->user()->id,
                ]
            );
        }

        return response()->json(['message' => count($data['grades']).' grades saved. Return them when you are ready.']);
    }

    /** Return graded work so students can see their marks. Omit ids to return everything graded. */
    public function returnMany(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('grade', $assignment);

        $ids = $request->validate([
            'submission_ids' => ['nullable', 'array'],
            'submission_ids.*' => ['integer'],
        ])['submission_ids'] ?? null;

        $submissions = $assignment->submissions()
            ->whereNotNull('marks')
            ->whereNull('returned_at')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->get();

        $this->returnSubmissions($assignment, $submissions);

        return response()->json(['message' => $submissions->count().' grade(s) returned to students.']);
    }

    /** Everything a teacher needs on one grading screen. */
    public function workspace(Assignment $assignment): JsonResponse
    {
        Gate::authorize('grade', $assignment);

        $assignment->load(['criteria.levels', 'section.course:id,code,title']);
        $roster = $assignment->assign_to === 'all'
            ? $assignment->section->students()->with('student:id,user_id,student_id')->orderBy('name')->get(['users.id', 'users.name', 'users.email'])
            : $assignment->assignedStudents()->with('student:id,user_id,student_id')->orderBy('name')->get(['users.id', 'users.name', 'users.email']);

        $submissions = $assignment->submissions()->with('versions')->get()->keyBy('student_id');

        $rows = $roster->map(function ($student) use ($submissions, $assignment) {
            $submission = $submissions->get($student->id);
            $state = match (true) {
                $submission?->marks !== null => 'graded',
                (bool) $submission?->isTurnedIn() => 'turned_in',
                default => 'missing',
            };

            return [
                'student' => ['id' => $student->id, 'name' => $student->name, 'email' => $student->email, 'student_id' => $student->student?->student_id],
                'state' => $state,
                'submission' => $submission ? $this->forTeacher($assignment, $submission) : null,
            ];
        });

        return response()->json([
            'assignment' => $assignment,
            'rubric_max' => $assignment->rubricMax(),
            'rows' => $rows,
            'counts' => $rows->countBy('state'),
        ]);
    }

    /** Signed, time-limited link to a submitted file (preview inline when possible). */
    public function file(Submission $submission): StreamedResponse
    {
        abort_unless($submission->file_path, 404);
        $inline = str_starts_with((string) $submission->file_mime, 'image/') || $submission->file_mime === 'application/pdf';

        return Storage::disk($submission->file_disk ?: 'local')->response(
            $submission->file_path,
            $submission->file_name ?: basename($submission->file_path),
            ['Content-Type' => $submission->file_mime ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
            $inline ? 'inline' : 'attachment',
        );
    }

    /** All turned-in files (and written answers) in one ZIP, one folder per student ID. */
    public function zip(Assignment $assignment): BinaryFileResponse
    {
        Gate::authorize('grade', $assignment);

        $submissions = $assignment->submissions()->whereNotNull('submitted_at')->with('student.student')->get();
        abort_if($submissions->isEmpty(), 422, 'Nobody has turned in work yet.');

        $path = tempnam(sys_get_temp_dir(), 'unisphere-zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($submissions as $s) {
            $folder = Str::slug(($s->student->student?->student_id ?? $s->student_id).' '.$s->student->name);
            if ($s->content) {
                $zip->addFromString("{$folder}/answer.txt", $s->content);
            }
            if ($s->file_path && Storage::disk($s->file_disk ?: 'local')->exists($s->file_path)) {
                $zip->addFromString("{$folder}/".($s->file_name ?: basename($s->file_path)), Storage::disk($s->file_disk ?: 'local')->get($s->file_path));
            }
        }
        $zip->close();

        $name = Str::slug($assignment->section->course->code.' '.$assignment->title).'-submissions.zip';

        return response()->download($path, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Marks sheet for offline grading: one row per student. */
    public function exportMarks(Assignment $assignment): StreamedResponse
    {
        Gate::authorize('grade', $assignment);

        $students = $assignment->section->students()->with('student:id,user_id,student_id')->orderBy('name')->get(['users.id', 'users.name']);
        $marks = $assignment->submissions()->get()->keyBy('student_id');

        return response()->streamDownload(function () use ($students, $marks, $assignment) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['student_id', 'name', 'marks', 'feedback']);
            foreach ($students as $u) {
                $s = $marks->get($u->id);
                fputcsv($out, [$u->student?->student_id, $u->name, $s?->marks, $s?->feedback]);
            }
            fputcsv($out, ['# max marks', (float) $assignment->max_marks]);
            fclose($out);
        }, Str::slug($assignment->title).'-marks.csv', ['Content-Type' => 'text/csv']);
    }

    /** Import the marks sheet back. Rows with an empty marks cell are skipped. */
    public function importMarks(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('grade', $assignment);

        $file = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']])['file'];
        $handle = fopen($file->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $col = fn (string $name) => array_search($name, $header, true);

        abort_if($col('student_id') === false || $col('marks') === false, 422, 'The file needs student_id and marks columns.');

        $enrolled = $assignment->section->students()->with('student:id,user_id,student_id')->get(['users.id'])
            ->mapWithKeys(fn ($u) => [$u->student?->student_id => $u->id]);

        $rows = [];
        $errors = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            $studentCode = trim((string) ($cells[$col('student_id')] ?? ''));
            $marks = trim((string) ($cells[$col('marks')] ?? ''));
            if ($studentCode === '' || str_starts_with($studentCode, '#') || $marks === '') {
                continue;
            }
            if (! isset($enrolled[$studentCode])) {
                $errors[] = "Line {$line}: {$studentCode} is not enrolled in this class.";
            } elseif (! is_numeric($marks) || $marks < 0 || $marks > (float) $assignment->max_marks) {
                $errors[] = "Line {$line}: marks must be between 0 and ".(float) $assignment->max_marks.'.';
            } else {
                $feedback = $col('feedback') !== false ? trim((string) ($cells[$col('feedback')] ?? '')) : null;
                $rows[] = ['student_id' => $enrolled[$studentCode], 'marks' => (float) $marks, 'feedback' => $feedback ?: null];
            }
        }
        fclose($handle);

        if ($errors) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        DB::transaction(function () use ($rows, $assignment, $request) {
            foreach ($rows as $row) {
                Submission::updateOrCreate(
                    ['assignment_id' => $assignment->id, 'student_id' => $row['student_id']],
                    ['marks' => $row['marks'], 'feedback' => $row['feedback'], 'graded_at' => now(), 'graded_by' => $request->user()->id]
                );
            }
        });

        return response()->json(['message' => count($rows).' marks imported. Return them when you are ready.']);
    }

    private function returnSubmissions(Assignment $assignment, $submissions): void
    {
        foreach ($submissions as $submission) {
            if ($submission->marks === null) {
                continue;
            }
            $submission->update(['returned_at' => now()]);
            $submission->student->notify(new GradesReturned($assignment, $submission));
        }
    }

    private function forTeacher(Assignment $assignment, Submission $submission): array
    {
        return [
            ...$submission->toArray(),
            'late_penalty_percent' => $assignment->latePenaltyPercent($submission),
            'effective_marks' => $assignment->effectiveMarks($submission),
        ];
    }

    /**
     * Sum the points of the chosen level for every criterion.
     *
     * @param  array<int|string, int|string>  $scores  criterion id => level id
     */
    private function rubricTotal(Assignment $assignment, array $scores): float
    {
        $total = 0.0;
        $errors = [];

        foreach ($assignment->criteria as $criterion) {
            $level = $criterion->levels->firstWhere('id', (int) ($scores[$criterion->id] ?? 0));

            if (! $level) {
                $errors["rubric_scores.{$criterion->id}"] = "Choose a level for \"{$criterion->title}\".";

                continue;
            }
            $total += (float) $level->points;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return round($total, 2);
    }
}
