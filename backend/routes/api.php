<?php

use App\Http\Controllers\Academic\AttendanceController;
use App\Http\Controllers\Academic\RegistrationController;
use App\Http\Controllers\Academic\ResultController;
use App\Http\Controllers\Academic\RoutineController;
use App\Http\Controllers\Admin\AdmissionController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\CourseSectionController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\ProgramController;
use App\Http\Controllers\Admin\SemesterController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Classroom\AssignmentController;
use App\Http\Controllers\Classroom\ClassroomController;
use App\Http\Controllers\Classroom\FeedbackCommentController;
use App\Http\Controllers\Classroom\GradebookController;
use App\Http\Controllers\Classroom\MaterialController;
use App\Http\Controllers\Classroom\QuizController;
use App\Http\Controllers\Classroom\StreamController;
use App\Http\Controllers\Classroom\SubmissionController;
use App\Http\Controllers\Classroom\TopicController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Finance\InvoiceController;
use App\Http\Controllers\Finance\PaymentController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| UniSphere REST API  (prefix: /api)
|--------------------------------------------------------------------------
| Roles: super_admin, registrar, department_head, teacher, accounts, student
| 'role:x,y' lets those roles (and super_admin) through.
| 'classroom' allows only members of the {section} classroom.
*/

Route::get('/', fn () => ['name' => 'UniSphere API', 'version' => '0.1.0']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// Public admission form
Route::get('/public/programs', [ApplicationController::class, 'programs']);
Route::post('/applications', [ApplicationController::class, 'store'])->middleware('throttle:5,1');
Route::post('/applications/status', [ApplicationController::class, 'status'])->middleware('throttle:10,1');

// Class stream attachments: signed, time-limited links (no token needed for <img>/<iframe> previews)
Route::get('/files/post-attachments/{attachment}', [StreamController::class, 'attachment'])
    ->middleware('signed')
    ->name('post-attachments.show');
Route::get('/files/assignment-attachments/{attachment}', [AssignmentController::class, 'attachment'])
    ->middleware('signed')
    ->name('assignment-attachments.show');
Route::get('/files/submissions/{submission}', [SubmissionController::class, 'file'])
    ->middleware('signed')
    ->name('submission-files.show');

// Sandbox payment gateway callback (disabled when PAYMENT_SANDBOX=false)
Route::get('/payments/{trxId}/sandbox-callback', [PaymentController::class, 'callback']);

// ---- Account (allowed even while a temporary password must be changed) ----
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);
});

Route::middleware(['auth:sanctum', 'active', 'password.changed'])->group(function () {

    // ---- Profile ------------------------------------------------------------
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/avatar', [AuthController::class, 'updateAvatar']);
    Route::get('/auth/activity', [AuthController::class, 'activity']);
    Route::get('/dashboard', DashboardController::class);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // ---- Notices & help desk ----------------------------------------------
    Route::get('/notices', [NoticeController::class, 'index']);
    Route::get('/notices/{notice}', [NoticeController::class, 'show']);
    Route::post('/notices', [NoticeController::class, 'store'])->middleware('role:registrar,department_head,teacher');
    Route::delete('/notices/{notice}', [NoticeController::class, 'destroy']);

    Route::apiResource('complaints', ComplaintController::class)->except('destroy');

    // ---- Virtual Classroom --------------------------------------------------
    Route::get('/classes', [ClassroomController::class, 'index']);
    Route::post('/classes/join', [ClassroomController::class, 'join'])->middleware('role:student');

    Route::prefix('classes/{section}')->middleware('classroom')->group(function () {
        Route::get('/', [ClassroomController::class, 'show']);
        Route::get('/people', [ClassroomController::class, 'people']);
        Route::post('/join-code', [ClassroomController::class, 'regenerateCode']);

        Route::get('/posts', [StreamController::class, 'index']);
        Route::post('/posts', [StreamController::class, 'store']);
        Route::put('/stream-settings', [StreamController::class, 'settings']);
        Route::get('/upcoming', [StreamController::class, 'upcoming']);

        Route::get('/materials', [MaterialController::class, 'index']);
        Route::post('/materials', [MaterialController::class, 'store']);

        Route::get('/topics', [TopicController::class, 'index']);
        Route::post('/topics', [TopicController::class, 'store']);
        Route::put('/topics/order', [TopicController::class, 'reorder']);

        Route::get('/assignments', [AssignmentController::class, 'index']);
        Route::post('/assignments', [AssignmentController::class, 'store']);

        Route::get('/quizzes', [QuizController::class, 'index']);
        Route::post('/quizzes', [QuizController::class, 'store']);

        Route::get('/gradebook', [GradebookController::class, 'show']);
        Route::get('/gradebook/summary', [GradebookController::class, 'summary']);
        Route::put('/gradebook/scheme', [GradebookController::class, 'updateScheme']);
        Route::post('/gradebook/sync', [GradebookController::class, 'sync']);

        Route::get('/attendance', [AttendanceController::class, 'index']);
        Route::post('/attendance', [AttendanceController::class, 'store']);
        Route::get('/attendance/report', [AttendanceController::class, 'report']);

        Route::get('/results', [ResultController::class, 'section']);
        Route::post('/results/publish', [ResultController::class, 'publish'])->middleware('role:registrar,department_head');
    });

    Route::patch('/posts/{post}', [StreamController::class, 'update']);
    Route::delete('/posts/{post}', [StreamController::class, 'destroy']);
    Route::post('/posts/{post}/comments', [StreamController::class, 'comment']);
    Route::patch('/comments/{comment}', [StreamController::class, 'updateComment']);
    Route::delete('/comments/{comment}', [StreamController::class, 'destroyComment']);

    Route::delete('/materials/{material}', [MaterialController::class, 'destroy']);

    Route::patch('/topics/{topic}', [TopicController::class, 'update']);
    Route::delete('/topics/{topic}', [TopicController::class, 'destroy']);
    Route::delete('/assignment-attachments/{attachment}', [AssignmentController::class, 'destroyAttachment']);

    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);
    Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);
    Route::post('/assignments/{assignment}/submit', [SubmissionController::class, 'store']);
    Route::post('/assignments/{assignment}/grades', [SubmissionController::class, 'gradeOffline']);
    Route::post('/assignments/{assignment}/unsubmit', [SubmissionController::class, 'unsubmit']);
    Route::get('/assignments/{assignment}/workspace', [SubmissionController::class, 'workspace']);
    Route::post('/assignments/{assignment}/return', [SubmissionController::class, 'returnMany']);
    Route::get('/assignments/{assignment}/submissions.zip', [SubmissionController::class, 'zip']);
    Route::get('/assignments/{assignment}/marks.csv', [SubmissionController::class, 'exportMarks']);
    Route::post('/assignments/{assignment}/marks-import', [SubmissionController::class, 'importMarks']);

    Route::middleware('role:registrar,department_head,teacher')->group(function () {
        Route::get('/comment-bank', [FeedbackCommentController::class, 'index']);
        Route::post('/comment-bank', [FeedbackCommentController::class, 'store']);
        Route::delete('/comment-bank/{feedback}', [FeedbackCommentController::class, 'destroy']);
    });
    Route::patch('/submissions/{submission}/grade', [SubmissionController::class, 'grade']);

    Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
    Route::post('/quizzes/{quiz}/start', [QuizController::class, 'start']);
    Route::post('/quizzes/{quiz}/submit', [QuizController::class, 'submit']);
    Route::patch('/quiz-attempts/{attempt}/grade', [QuizController::class, 'gradeAttempt']);

    // ---- Attendance ---------------------------------------------------------
    Route::get('/attendance-sessions/{session}', [AttendanceController::class, 'show']);
    Route::put('/attendance-sessions/{session}/records', [AttendanceController::class, 'mark']);
    Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn'])->middleware('role:student');

    // ---- Class routine --------------------------------------------------------
    Route::get('/routine/me', [RoutineController::class, 'mine']);
    Route::get('/routine/me.ics', [RoutineController::class, 'calendar']);
    Route::middleware('role:registrar,department_head,teacher')->group(function () {
        Route::get('/routine/rooms', [RoutineController::class, 'rooms']);
        Route::get('/routine/rooms/{room}', [RoutineController::class, 'room']);
    });

    // ---- Course registration & results -------------------------------------
    Route::middleware('role:student')->group(function () {
        Route::get('/registration/offered', [RegistrationController::class, 'offered']);
        Route::get('/registration/mine', [RegistrationController::class, 'mine']);
        Route::post('/registration', [RegistrationController::class, 'store']);
        Route::delete('/registration/{registration}', [RegistrationController::class, 'drop']);
        Route::get('/results/mine', [ResultController::class, 'mine']);
    });

    Route::middleware('role:registrar,department_head,teacher')->group(function () {
        Route::get('/registration/pending', [RegistrationController::class, 'pending']);
        Route::patch('/registration/{registration}/decide', [RegistrationController::class, 'decide']);
        Route::patch('/registration/decide-many', [RegistrationController::class, 'decideMany']);
    });

    Route::post('/registration/force', [RegistrationController::class, 'forceAdd'])->middleware('role:registrar');

    // ---- Fees & payments ----------------------------------------------------
    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('role:student,accounts');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('role:student,accounts');
    Route::post('/invoices/{invoice}/pay', [PaymentController::class, 'initiate'])->middleware('role:student');

    Route::middleware('role:accounts')->group(function () {
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update']);
        Route::post('/invoices/{invoice}/cash', [PaymentController::class, 'recordCash']);
    });

    // ---- University administration -----------------------------------------
    Route::get('/semesters', [SemesterController::class, 'index']);
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::get('/programs', [ProgramController::class, 'index']);

    Route::prefix('admin')->middleware('role:registrar')->group(function () {
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);

        Route::get('import/{type}/template', [ImportController::class, 'template']);
        Route::post('import/{type}/preview', [ImportController::class, 'preview']);
        Route::post('import/{type}', [ImportController::class, 'import']);
        Route::get('students/export', [ImportController::class, 'exportStudents']);

        Route::get('applications', [AdmissionController::class, 'index']);
        Route::get('applications/{application}', [AdmissionController::class, 'show']);
        Route::get('applications/{application}/documents/{index}', [AdmissionController::class, 'document'])->whereNumber('index');
        Route::post('applications/{application}/decide', [AdmissionController::class, 'decide']);
        Route::post('semesters/{semester}/make-current', [SemesterController::class, 'makeCurrent']);
        Route::apiResource('users', UserController::class);
        Route::apiResource('departments', DepartmentController::class)->except('index');
        Route::apiResource('programs', ProgramController::class)->except('index');
        Route::apiResource('semesters', SemesterController::class)->except('index');
        Route::apiResource('courses', CourseController::class);
        Route::apiResource('sections', CourseSectionController::class);
    });
});
