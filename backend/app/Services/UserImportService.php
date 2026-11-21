<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CSV import for students and faculty.
 *
 * preview() validates every row and reports errors per line without saving.
 * import()  saves everything in one transaction, only if no row has errors.
 */
class UserImportService
{
    public const COLUMNS = [
        'students' => ['name', 'email', 'phone', 'student_id', 'program_code', 'batch', 'advisor_email'],
        'faculty' => ['name', 'email', 'phone', 'employee_id', 'department_code', 'designation', 'role'],
    ];

    public const MAX_ROWS = 2000;

    public function __construct(private readonly StudentIdGenerator $ids)
    {
    }

    /** Header line plus one example row. */
    public function template(string $type): string
    {
        $example = $type === 'students'
            ? ['Ayesha Rahman', 'ayesha@example.com', '01712345678', '', 'BSCSE', '2026', 'teacher@unisphere.test']
            : ['Dr. Kamal Hossain', 'kamal@example.com', '01812345678', 'F-3001', 'CSE', 'Assistant Professor', 'teacher'];

        return implode(',', self::COLUMNS[$type])."\n".implode(',', $example)."\n";
    }

    /** @return array{rows: array<int, array>, valid: int, invalid: int} */
    public function preview(string $type, UploadedFile $file): array
    {
        $rows = $this->parse($type, $file);
        $programs = Program::pluck('id', 'code');
        $departments = Department::pluck('id', 'code');
        $faculty = User::whereIn('role', Role::faculty())->pluck('id', 'email');

        $seenEmails = [];
        $seenIds = [];

        foreach ($rows as &$row) {
            $d = $row['data'];
            $rules = [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
                'phone' => ['nullable', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            ];

            if ($type === 'students') {
                $rules += [
                    'student_id' => ['nullable', 'string', 'max:30', Rule::unique('students', 'student_id')],
                    'program_code' => ['required', Rule::in($programs->keys())],
                    'batch' => ['nullable', 'string', 'max:20'],
                    'advisor_email' => ['nullable', Rule::in($faculty->keys())],
                ];
            } else {
                $rules += [
                    'employee_id' => ['required', 'string', 'max:30', Rule::unique('faculty_members', 'employee_id')],
                    'department_code' => ['required', Rule::in($departments->keys())],
                    'designation' => ['nullable', 'string', 'max:60'],
                    'role' => ['nullable', Rule::in(Role::faculty())],
                ];
            }

            $errors = Validator::make($d, $rules, [
                'program_code.in' => 'Unknown program code.',
                'department_code.in' => 'Unknown department code.',
                'advisor_email.in' => 'No teacher has this email.',
                'phone.regex' => 'Not a valid Bangladeshi mobile number.',
                'email.unique' => 'An account with this email already exists.',
            ])->errors()->all();

            // Duplicates inside the file itself.
            $email = strtolower($d['email'] ?? '');
            if ($email && isset($seenEmails[$email])) {
                $errors[] = "Email repeats line {$seenEmails[$email]}.";
            }
            $seenEmails[$email] = $row['line'];

            $ident = $d['student_id'] ?? $d['employee_id'] ?? '';
            if ($ident && isset($seenIds[$ident])) {
                $errors[] = "ID repeats line {$seenIds[$ident]}.";
            }
            if ($ident) {
                $seenIds[$ident] = $row['line'];
            }

            $row['errors'] = $errors;
        }
        unset($row);

        $invalid = count(array_filter($rows, fn ($r) => $r['errors']));

        return ['rows' => $rows, 'valid' => count($rows) - $invalid, 'invalid' => $invalid];
    }

    /**
     * @return array{created: int, credentials: array<int, array>}|array{rows: array, valid: int, invalid: int}
     */
    public function import(string $type, UploadedFile $file): array
    {
        $preview = $this->preview($type, $file);
        if ($preview['invalid'] > 0) {
            return $preview;
        }

        $programs = Program::all()->keyBy('code');
        $departments = Department::pluck('id', 'code');
        $faculty = User::whereIn('role', Role::faculty())->pluck('id', 'email');

        $credentials = DB::transaction(function () use ($type, $preview, $programs, $departments, $faculty) {
            $out = [];

            foreach ($preview['rows'] as $row) {
                $d = $row['data'];
                $password = Str::password(10, symbols: false);

                $user = User::create([
                    'name' => $d['name'],
                    'email' => strtolower($d['email']),
                    'phone' => $d['phone'] ?: null,
                    'role' => $type === 'students' ? Role::Student->value : ($d['role'] ?: Role::Teacher->value),
                    'password' => $password,
                    'must_change_password' => true,
                ]);

                if ($type === 'students') {
                    $program = $programs[$d['program_code']];
                    $studentId = $d['student_id'] ?: $this->ids->next($program);
                    Student::create([
                        'user_id' => $user->id,
                        'program_id' => $program->id,
                        'advisor_id' => $d['advisor_email'] ? $faculty[$d['advisor_email']] : null,
                        'student_id' => $studentId,
                        'batch' => $d['batch'] ?: now()->format('Y'),
                        'admission_date' => now()->toDateString(),
                    ]);
                } else {
                    $user->faculty()->create([
                        'department_id' => $departments[$d['department_code']],
                        'employee_id' => $d['employee_id'],
                        'designation' => $d['designation'] ?: 'Lecturer',
                    ]);
                    $studentId = null;
                }

                $out[] = [
                    'name' => $user->name,
                    'email' => $user->email,
                    'id' => $studentId ?? $d['employee_id'],
                    'temporary_password' => $password,
                ];
            }

            return $out;
        });

        return ['created' => count($credentials), 'credentials' => $credentials];
    }

    /** @return array<int, array{line: int, data: array<string, string>}> */
    private function parse(string $type, UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        abort_if($handle === false, 422, 'The file could not be read.');

        $header = fgetcsv($handle);
        abort_if(! $header, 422, 'The file is empty.');

        // Strip a UTF-8 BOM (Excel adds one) and normalise header names.
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $missing = array_diff(['name', 'email'], $header);
        abort_if($missing !== [], 422, 'Missing columns: '.implode(', ', $missing).'. Download the template to see the expected columns.');

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // skip blank lines
            }

            $data = [];
            foreach (self::COLUMNS[$type] as $column) {
                $index = array_search($column, $header, true);
                $data[$column] = $index === false ? '' : trim((string) ($cells[$index] ?? ''));
            }
            $rows[] = ['line' => $line, 'data' => $data];

            abort_if(count($rows) > self::MAX_ROWS, 422, 'Import at most '.self::MAX_ROWS.' rows at a time.');
        }
        fclose($handle);

        abort_if($rows === [], 422, 'The file has no data rows.');

        return $rows;
    }
}
