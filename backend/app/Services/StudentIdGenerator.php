<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Student;

/**
 * Generates the next student ID from config('unisphere.student_id.format').
 *
 * Default format "{year}-{term}-{program}-{seq}" gives e.g. 2026-3-01-007:
 *   {year}     admission year
 *   {term}     1 = Spring (Jan-Apr), 2 = Summer (May-Aug), 3 = Fall (Sep-Dec)
 *   {program}  program id, zero-padded to 2 digits
 *   {seq}      running number within that prefix, zero-padded
 */
class StudentIdGenerator
{
    public function next(Program $program, ?\DateTimeInterface $date = null): string
    {
        $date ??= now();
        $month = (int) $date->format('n');

        $prefix = strtr(config('unisphere.student_id.format'), [
            '{year}' => $date->format('Y'),
            '{term}' => (string) ($month <= 4 ? 1 : ($month <= 8 ? 2 : 3)),
            '{program}' => str_pad((string) $program->id, 2, '0', STR_PAD_LEFT),
            '{seq}' => '',
        ]);

        // Highest existing number with this prefix (lockForUpdate prevents duplicates under concurrency).
        $last = Student::query()
            ->where('student_id', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('student_id')
            ->map(fn (string $id) => (int) substr($id, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), config('unisphere.student_id.sequence_digits'), '0', STR_PAD_LEFT);
    }
}
