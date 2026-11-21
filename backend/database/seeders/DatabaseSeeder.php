<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Notice;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data. Every account's password is "password".
 *
 *   admin@unisphere.test      Super Admin
 *   registrar@unisphere.test  Registrar
 *   head.cse@unisphere.test   Department Head (CSE)
 *   teacher@unisphere.test    Teacher
 *   accounts@unisphere.test   Accounts Officer
 *   student@unisphere.test    Student
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = $this->user('Super Admin', 'admin@unisphere.test', Role::SuperAdmin);
        $this->user('Registrar Office', 'registrar@unisphere.test', Role::Registrar);
        $head = $this->user('Dr. Head of CSE', 'head.cse@unisphere.test', Role::DepartmentHead);
        $this->user('Accounts Officer', 'accounts@unisphere.test', Role::Accounts);

        $cse = Department::create(['name' => 'Computer Science and Engineering', 'code' => 'CSE', 'head_id' => $head->id]);
        Department::create(['name' => 'Business Administration', 'code' => 'BBA']);
        $bsc = Program::create(['department_id' => $cse->id, 'name' => 'B.Sc. in CSE', 'code' => 'BSCSE', 'total_credits' => 160]);

        $head->faculty()->create(['department_id' => $cse->id, 'employee_id' => 'F-1001', 'designation' => 'Professor']);

        $teachers = collect([
            ['Md. Rahim Uddin', 'teacher@unisphere.test'],
            ['Nusrat Jahan', 'nusrat@unisphere.test'],
            ['Tanvir Ahmed', 'tanvir@unisphere.test'],
        ])->map(function ($t, $i) use ($cse) {
            $u = $this->user($t[0], $t[1], Role::Teacher);
            $u->faculty()->create(['department_id' => $cse->id, 'employee_id' => 'F-'.(2001 + $i), 'designation' => 'Lecturer']);

            return $u;
        });

        $semester = Semester::create([
            'name' => 'Fall 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31',
            'registration_open' => true, 'is_current' => true,
        ]);

        $courses = collect([
            ['CSE 4101', 'Software Engineering', 3.0],
            ['CSE 4103', 'Artificial Intelligence', 3.0],
            ['CSE 4105', 'Computer Networks', 3.0],
            ['CSE 4107', 'Web Engineering Lab', 1.5],
            ['CSE 4109', 'Compiler Design', 3.0],
        ])->map(fn ($c) => Course::create([
            'program_id' => $bsc->id, 'code' => $c[0], 'title' => $c[1], 'credit' => $c[2],
        ]));

        $colors = ['indigo', 'emerald', 'amber', 'rose', 'sky'];
        // A clash-free week: no teacher, room or student has two classes at once.
        $schedules = [
            [['day' => 'Sun', 'start' => '09:00', 'end' => '10:30'], ['day' => 'Tue', 'start' => '09:00', 'end' => '10:30']],
            [['day' => 'Sun', 'start' => '10:30', 'end' => '12:00'], ['day' => 'Tue', 'start' => '10:30', 'end' => '12:00']],
            [['day' => 'Mon', 'start' => '09:00', 'end' => '10:30'], ['day' => 'Wed', 'start' => '09:00', 'end' => '10:30']],
            [['day' => 'Mon', 'start' => '14:00', 'end' => '17:00']],
            [['day' => 'Thu', 'start' => '09:00', 'end' => '10:30'], ['day' => 'Sat', 'start' => '09:00', 'end' => '10:30']],
        ];
        $sections = $courses->map(fn (Course $course, $i) => CourseSection::create([
            'course_id' => $course->id,
            'semester_id' => $semester->id,
            'teacher_id' => $teachers[$i % 3]->id,
            'section' => 'A',
            'room' => '70'.($i + 1),
            'capacity' => 40,
            'cover_color' => $colors[$i],
            'schedule' => $schedules[$i],
        ]));

        // Students: one known demo account + 24 generated, all enrolled in the first 4 courses.
        $students = collect([$this->user('Nayan Ray', 'student@unisphere.test', Role::Student)])
            ->merge(User::factory(24)->create());

        foreach ($students as $i => $student) {
            $student->student()->create([
                'program_id' => $bsc->id,
                'advisor_id' => $teachers[0]->id,
                'student_id' => '2022-1-60-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'batch' => '2022',
                'admission_date' => '2022-09-01',
            ]);

            foreach ($sections->take(4) as $section) {
                Registration::create([
                    'course_section_id' => $section->id,
                    'student_id' => $student->id,
                    'status' => Registration::APPROVED,
                    'approved_by' => $teachers[0]->id,
                ]);
            }

            Invoice::create([
                'student_id' => $student->id, 'semester_id' => $semester->id,
                'title' => 'Fall 2026 tuition fee', 'amount' => 65000, 'due_date' => '2026-12-15',
            ]);
        }

        // Some classwork in the first class
        $first = $sections->first();
        $first->posts()->create([
            'author_id' => $first->teacher_id,
            'body' => 'Welcome to Software Engineering! Please read the course outline in Classwork before our first lab.',
            'is_pinned' => true,
        ]);
        $first->materials()->create([
            'author_id' => $first->teacher_id, 'title' => 'Course outline', 'topic' => 'Week 1',
            'type' => 'link', 'url' => 'https://example.com/course-outline.pdf',
        ]);
        Assignment::create([
            'course_section_id' => $first->id, 'author_id' => $first->teacher_id,
            'title' => 'SRS document for your semester project', 'topic' => 'Week 2',
            'instructions' => 'Write an IEEE 830 style SRS for your team project.',
            'category' => 'assignment', 'max_marks' => 20,
            'due_at' => now()->addWeek(), 'published_at' => now(),
        ]);

        Notice::create([
            'author_id' => $admin->id, 'title' => 'Fall 2026 course registration is open',
            'body' => 'Register for your courses from the Registration page before the add/drop deadline.',
            'audience' => 'students', 'is_urgent' => true, 'published_at' => now(),
        ]);
        Notice::create([
            'author_id' => $admin->id, 'title' => 'Faculty meeting on Thursday',
            'body' => 'All faculty members are requested to attend the meeting at 11:00 AM in the conference room.',
            'audience' => 'faculty', 'published_at' => now(),
        ]);
    }

    private function user(string $name, string $email, Role $role): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email, 'role' => $role->value]);
    }
}
