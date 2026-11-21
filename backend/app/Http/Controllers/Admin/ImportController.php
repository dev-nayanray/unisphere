<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bulk onboarding from CSV: GET template, POST preview, POST import. Also the student list export. */
class ImportController extends Controller
{
    public function __construct(private readonly UserImportService $importer)
    {
    }

    public function template(string $type): StreamedResponse
    {
        $this->ensureType($type);

        return response()->streamDownload(
            fn () => print($this->importer->template($type)),
            "unisphere-{$type}-template.csv",
            ['Content-Type' => 'text/csv']
        );
    }

    public function preview(Request $request, string $type): JsonResponse
    {
        $this->ensureType($type);

        return response()->json($this->importer->preview($type, $this->file($request)));
    }

    public function import(Request $request, string $type): JsonResponse
    {
        $this->ensureType($type);
        $result = $this->importer->import($type, $this->file($request));

        if (isset($result['invalid'])) {
            return response()->json([
                'message' => "{$result['invalid']} row(s) have errors. Nothing was imported.",
                ...$result,
            ], 422);
        }

        return response()->json([
            'message' => "{$result['created']} account(s) created. Share each temporary password securely.",
            ...$result,
        ], 201);
    }

    /** Student list as CSV (opens directly in Excel). Optional ?program_id= and ?batch= filters. */
    public function exportStudents(Request $request): StreamedResponse
    {
        $students = User::query()
            ->where('role', 'student')
            ->whereHas('student', fn ($q) => $q
                ->when($request->program_id, fn ($q, $id) => $q->where('program_id', $id))
                ->when($request->batch, fn ($q, $b) => $q->where('batch', $b)))
            ->with(['student.program:id,code', 'student.advisor:id,name'])
            ->orderBy('name')
            ->get();

        return response()->streamDownload(function () use ($students) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Bangla names correctly
            fputcsv($out, ['Student ID', 'Name', 'Email', 'Phone', 'Program', 'Batch', 'Advisor', 'CGPA', 'Status', 'Active']);
            foreach ($students as $u) {
                fputcsv($out, [
                    $u->student->student_id, $u->name, $u->email, $u->phone, $u->student->program->code,
                    $u->student->batch, $u->student->advisor?->name, $u->student->cgpa, $u->student->status,
                    $u->is_active ? 'yes' : 'no',
                ]);
            }
            fclose($out);
        }, 'unisphere-students-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function file(Request $request)
    {
        return $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ])['file'];
    }

    private function ensureType(string $type): void
    {
        abort_unless(array_key_exists($type, UserImportService::COLUMNS), 404);
    }
}
