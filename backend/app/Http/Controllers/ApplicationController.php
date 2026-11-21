<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public admission form. No login needed. */
class ApplicationController extends Controller
{
    public function programs(): JsonResponse
    {
        return response()->json(
            Program::with('department:id,code,name')->orderBy('name')->get(['id', 'department_id', 'name', 'code'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $year = (int) now()->format('Y');

        $data = $request->validate([
            'program_id' => ['required', 'exists:programs,id'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'date_of_birth' => ['required', 'date', 'before:-15 years'],
            'gender' => ['required', 'in:male,female,other'],
            'address' => ['required', 'string', 'max:500'],
            'ssc_gpa' => ['required', 'numeric', 'between:1,5'],
            'ssc_year' => ['required', 'integer', 'between:'.($year - 10).','.$year],
            'hsc_gpa' => ['required', 'numeric', 'between:1,5'],
            'hsc_year' => ['required', 'integer', 'between:'.($year - 10).','.$year, 'gte:ssc_year'],
            'documents' => ['required', 'array', 'min:1', 'max:5'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [
            'phone.regex' => 'Enter a valid Bangladeshi mobile number, e.g. 01712345678.',
            'date_of_birth.before' => 'Applicants must be at least 15 years old.',
            'documents.required' => 'Upload at least one document (SSC/HSC certificates or transcripts).',
        ]);

        // Documents are private: stored on the local disk, served only to the registrar.
        $documents = collect($request->file('documents'))->map(fn ($file) => [
            'original_name' => $file->getClientOriginalName(),
            'path' => $file->store('applications', 'local'),
        ])->all();

        $application = Application::create([...collect($data)->except('documents')->all(), 'documents' => $documents]);

        return response()->json([
            'message' => 'Application submitted. Keep your tracking code to check the status.',
            'tracking_code' => $application->tracking_code,
        ], 201);
    }

    /** Check status with tracking code + email (both required, so codes can't be enumerated). */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $application = Application::with('program:id,code,name')
            ->where('tracking_code', strtoupper($data['tracking_code']))
            ->where('email', $data['email'])
            ->firstOrFail();

        return response()->json([
            'tracking_code' => $application->tracking_code,
            'name' => $application->name,
            'program' => $application->program,
            'status' => $application->status,
            'review_note' => $application->isDecided() ? $application->review_note : null,
            'submitted_at' => $application->created_at,
        ]);
    }
}
