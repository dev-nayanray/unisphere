<?php

/*
|--------------------------------------------------------------------------
| UniSphere academic settings
|--------------------------------------------------------------------------
| The grading scale follows the UGC Bangladesh uniform grading system.
| Weights are the default course grading scheme; every course section
| can override them in its `grading_scheme` column.
*/

return [

    // Where the React app lives; used for links in emails (e.g. password reset).
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    // Format for generated student IDs (see App\Services\StudentIdGenerator).
    'student_id' => [
        'format' => env('STUDENT_ID_FORMAT', '{year}-{term}-{program}-{seq}'),
        'sequence_digits' => 3,
    ],

    'min_attendance_percent' => 75,

    'max_credits_per_semester' => 21,

    'default_grading_scheme' => [
        'attendance' => 10,
        'assignment' => 10,
        'quiz' => 10,
        'mid' => 20,
        'final' => 50,
    ],

    // [minimum percentage, letter grade, grade point]
    'grade_scale' => [
        [80, 'A+', 4.00],
        [75, 'A', 3.75],
        [70, 'A-', 3.50],
        [65, 'B+', 3.25],
        [60, 'B', 3.00],
        [55, 'B-', 2.75],
        [50, 'C+', 2.50],
        [45, 'C', 2.25],
        [40, 'D', 2.00],
        [0, 'F', 0.00],
    ],

    'qr_attendance_ttl_minutes' => 10,

    // Sandbox payments: the callback URL simulates bKash / SSLCommerz results.
    'payment_sandbox' => (bool) env('PAYMENT_SANDBOX', true),
];
