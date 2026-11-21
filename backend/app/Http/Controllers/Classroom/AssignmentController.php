<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\CourseSection;
use App\Models\Post;
use App\Notifications\NewClassworkPosted;
use App\Support\SafeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssignmentController extends Controller
{
    public const MAX_ATTACHMENTS = 5;

    /** Classwork list. Students see published work assigned to them, with their own submission. */
    public function index(Request $request, CourseSection $section): JsonResponse
    {
        $user = $request->user();
        $manage = Gate::allows('manage', $section);

        $assignments = $section->assignments()
            ->with('topic:id,name')
            ->when(! $manage, fn ($q) => $q
                ->visibleToStudent($user)
                ->with(['submissions' => fn ($q) => $q->where('student_id', $user->id)]))
            ->when($manage, fn ($q) => $q->withCount([
                'submissions as turned_in_count' => fn ($q) => $q->whereNotNull('submitted_at'),
                'submissions as graded_count' => fn ($q) => $q->whereNotNull('marks'),
            ]))
            ->orderByRaw('due_at IS NULL, due_at')
            ->get();

        return response()->json($assignments);
    }

    public function store(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);
        $data = $this->validated($request, $section);

        $assignment = DB::transaction(function () use ($data, $section, $request) {
            $assignment = $section->assignments()->create([
                'assign_to' => 'all',
                'late_policy' => Assignment::LATE_ACCEPT,
                ...$this->fields($data),
                'author_id' => $request->user()->id,
                'published_at' => $this->publishTime($data),
            ]);

            $this->syncStudents($assignment, $data);
            $this->syncRubric($assignment, $data);
            $this->storeAttachments($assignment, $request);

            return $assignment;
        });

        $this->announceIfDue($assignment);

        return response()->json($assignment->load(['topic', 'attachments', 'criteria.levels']), 201);
    }

    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('view', $assignment);
        $user = $request->user();
        $assignment->load(['section.course:id,code,title', 'topic:id,name', 'attachments', 'criteria.levels']);

        if (Gate::allows('update', $assignment)) {
            $assignment->load(['submissions.student:id,name,email', 'assignedStudents:id']);
            $roster = $assignment->assign_to === 'all'
                ? $assignment->section->students()->get(['users.id', 'users.name'])
                : $assignment->assignedStudents()->get(['users.id', 'users.name']);

            $assignment->submissions->each(fn ($s) => $s->setAttribute('late_penalty_percent', $assignment->latePenaltyPercent($s))
                ->setAttribute('effective_marks', $assignment->effectiveMarks($s)));

            return response()->json([...$assignment->toArray(), 'roster' => $roster, 'rubric_max' => $assignment->rubricMax()]);
        }

        $mine = $assignment->submissions()->where('student_id', $user->id)->first();
        $assignment->unsetRelation('submissions');

        return response()->json([
            ...$assignment->toArray(),
            'rubric_max' => $assignment->rubricMax(),
            'submissions' => $mine ? [[
                ...$mine->toArray(),
                'late_penalty_percent' => $assignment->latePenaltyPercent($mine),
                'effective_marks' => $assignment->effectiveMarks($mine),
            ]] : [],
        ]);
    }

    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('update', $assignment);
        $data = $this->validated($request, $assignment->section, $assignment);

        if (array_key_exists('rubric', $data) && $assignment->submissions()->whereNotNull('rubric_scores')->exists()) {
            throw ValidationException::withMessages(['rubric' => 'Submissions were already graded with this rubric, so it can no longer be changed.']);
        }

        $wasVisible = $assignment->isPublished();

        DB::transaction(function () use ($assignment, $data, $request) {
            $fields = $this->fields($data);
            if (array_key_exists('status', $data)) {
                $fields['published_at'] = $data['status'] === 'publish'
                    ? ($assignment->isPublished() ? $assignment->published_at : now())
                    : $this->publishTime($data);
                if ($data['status'] !== 'publish') {
                    $fields['notified_at'] = null;
                }
            }
            $assignment->update($fields);

            $this->syncStudents($assignment, $data);
            $this->syncRubric($assignment, $data);
            $this->storeAttachments($assignment, $request);
        });

        if (! $wasVisible) {
            $this->announceIfDue($assignment->fresh());
        }

        return response()->json($assignment->fresh(['topic', 'attachments', 'criteria.levels', 'assignedStudents:id']));
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        Gate::authorize('delete', $assignment);

        foreach ($assignment->attachments as $file) {
            Storage::disk('local')->delete($file->path);
        }
        $assignment->delete();

        return response()->json(['message' => 'Assignment deleted.']);
    }

    public function destroyAttachment(AssignmentAttachment $attachment): JsonResponse
    {
        Gate::authorize('update', $attachment->assignment);

        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        return response()->json(['message' => 'Attachment removed.']);
    }

    /** Signed, time-limited link (see AssignmentAttachment::url). */
    public function attachment(AssignmentAttachment $attachment): StreamedResponse
    {
        return Storage::disk('local')->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff'],
            ($attachment->is_image || $attachment->is_pdf) ? 'inline' : 'attachment',
        );
    }

    /** Notify the right students and post to the stream once the assignment is visible. */
    public static function announceIfDue(Assignment $assignment): void
    {
        if (! $assignment->isPublished() || $assignment->notified_at) {
            return;
        }

        $section = $assignment->section;
        $students = $assignment->assign_to === 'all' ? $section->students()->get() : $assignment->assignedStudents()->get();

        Notification::send($students, new NewClassworkPosted($assignment));
        Post::announceClasswork($section, $assignment->author, 'assignment', $assignment->id, $assignment->title);
        $assignment->update(['notified_at' => now()]);
    }

    private function validated(Request $request, CourseSection $section, ?Assignment $assignment = null): array
    {
        // FormData can't nest objects, so the rubric may arrive as a JSON string.
        if (is_string($request->input('rubric'))) {
            $request->merge(['rubric' => json_decode($request->input('rubric'), true) ?? []]);
        }

        $req = $assignment ? 'sometimes' : 'required';

        $data = $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'topic_id' => ['nullable', Rule::exists('topics', 'id')->where('course_section_id', $section->id)],
            'category' => ['sometimes', Rule::in(Assignment::CATEGORIES)],
            'max_marks' => ['sometimes', 'numeric', 'min:1', 'max:1000'],
            'due_at' => ['nullable', 'date'],
            'late_policy' => ['sometimes', Rule::in([Assignment::LATE_ACCEPT, Assignment::LATE_PENALTY, Assignment::LATE_REJECT])],
            'late_penalty_percent' => ['required_if:late_policy,penalty', 'nullable', 'integer', 'min:1', 'max:100'],
            'assign_to' => ['sometimes', Rule::in(['all', 'selected'])],
            'student_ids' => ['required_if:assign_to,selected', 'array'],
            'student_ids.*' => ['integer', Rule::exists('registrations', 'student_id')
                ->where('course_section_id', $section->id)->where('status', 'approved')],
            'status' => ['sometimes', Rule::in(['draft', 'publish', 'schedule'])],
            'publish_at' => ['required_if:status,schedule', 'nullable', 'date', 'after:now'],
            // Backwards compatible: "publish": true/false
            'publish' => ['sometimes', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'max:20480'],
            'rubric' => ['nullable', 'array', 'max:12'],
            'rubric.*.title' => ['required', 'string', 'max:255'],
            'rubric.*.description' => ['nullable', 'string', 'max:500'],
            'rubric.*.levels' => ['required', 'array', 'min:2', 'max:6'],
            'rubric.*.levels.*.title' => ['required', 'string', 'max:80'],
            'rubric.*.levels.*.description' => ['nullable', 'string', 'max:300'],
            'rubric.*.levels.*.points' => ['required', 'numeric', 'min:0', 'max:1000'],
        ], [
            'student_ids.*.exists' => 'Every selected student must be enrolled in this class.',
            'student_ids.required_if' => 'Choose at least one student.',
        ]);

        if (! isset($data['status']) && array_key_exists('publish', $data)) {
            $data['status'] = $data['publish'] ? 'publish' : 'draft';
        }
        if (! $assignment && ! isset($data['status'])) {
            $data['status'] = 'publish';
        }

        $existing = $assignment?->attachments()->count() ?? 0;
        if ($existing + count($request->file('attachments', [])) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages(['attachments' => 'An assignment can have at most '.self::MAX_ATTACHMENTS.' files.']);
        }

        return $data;
    }

    /** Columns that come straight from the request. */
    private function fields(array $data): array
    {
        $fields = collect($data)->only([
            'title', 'topic_id', 'category', 'max_marks', 'due_at', 'late_policy', 'late_penalty_percent', 'assign_to',
        ])->all();

        if (array_key_exists('instructions', $data)) {
            $fields['instructions'] = SafeText::clean($data['instructions']);
        }
        if (($data['late_policy'] ?? null) && $data['late_policy'] !== Assignment::LATE_PENALTY) {
            $fields['late_penalty_percent'] = 0;
        }

        return $fields;
    }

    private function publishTime(array $data): mixed
    {
        return match ($data['status'] ?? 'publish') {
            'publish' => now(),
            'schedule' => $data['publish_at'],
            default => null, // draft
        };
    }

    private function syncStudents(Assignment $assignment, array $data): void
    {
        if ($assignment->assign_to === 'selected' && isset($data['student_ids'])) {
            $assignment->assignedStudents()->sync($data['student_ids']);
        } elseif ($assignment->assign_to === 'all') {
            $assignment->assignedStudents()->detach();
        }
    }

    /** Replace the rubric; the assignment's points become the rubric's maximum. */
    private function syncRubric(Assignment $assignment, array $data): void
    {
        if (! array_key_exists('rubric', $data)) {
            return;
        }

        $assignment->criteria()->delete();

        foreach (array_values($data['rubric'] ?? []) as $i => $criterion) {
            $row = $assignment->criteria()->create([
                'title' => $criterion['title'],
                'description' => $criterion['description'] ?? null,
                'position' => $i,
            ]);
            foreach (array_values($criterion['levels']) as $j => $level) {
                $row->levels()->create([...$level, 'position' => $j]);
            }
        }

        $assignment->unsetRelation('criteria');
        if ($max = $assignment->rubricMax()) {
            $assignment->update(['max_marks' => $max]);
        }
    }

    private function storeAttachments(Assignment $assignment, Request $request): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $assignment->attachments()->create([
                'path' => $file->store("classes/{$assignment->course_section_id}/assignments/{$assignment->id}", 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
            ]);
        }
    }
}
