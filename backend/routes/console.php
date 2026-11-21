<?php

use App\Models\Student;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('unisphere:recalculate-cgpa', function () {
    Student::with('user')->each(fn (Student $student) => $student->recalculateCgpa());
    $this->info('CGPA recalculated for all students.');
})->purpose('Recalculate every student CGPA from published results');

Schedule::command('unisphere:recalculate-cgpa')->dailyAt('02:00');

Schedule::command('unisphere:publish-scheduled-posts')->everyMinute()->withoutOverlapping();
