# UniSphere

**An Integrated Smart University Management & Virtual Classroom System**

UniSphere puts university administration and online teaching on one platform, with one login and one database. Teachers run Google Classroom-style classes (stream, classwork, quizzes, grading). Their grades flow straight into official results, so marks are never entered twice.

> 7th-semester practical project. The full proposal is in [`docs/UniSphere_Project_Proposal.docx`](docs/UniSphere_Project_Proposal.docx).

## Modules

| Area | What's included |
| --- | --- |
| **Virtual Classroom** | Classes per course section, join codes, class stream with comments and pinning, materials (file/link/video), assignments with submissions and late flags, MCQ/short-answer quizzes with auto-grading, weighted gradebook |
| **Academic** | Departments, programs, semesters, courses with prerequisites, course offering, online registration (prerequisite, credit-limit and capacity checks) with advisor approval, attendance (list or QR check-in, 75% warning), results with UGC grading, GPA/CGPA, transcript |
| **Finance** | Invoices (single or bulk by program/batch), waivers, bKash/SSLCommerz **sandbox** payments, cash payments at the counter |
| **Services** | Notice board with audience targeting and in-app notifications, help desk tickets, role-based dashboards |
| **Platform** | Sanctum token auth, 6 roles with RBAC, PWA (installable, offline shell), Docker services, GitHub Actions CI |

**Roles:** `super_admin`, `registrar`, `department_head`, `teacher`, `accounts`, `student`

## Tech stack

- **Backend:** Laravel 12 (PHP 8.2+), Laravel Sanctum, MySQL 8 (SQLite for tests)
- **Frontend:** React 19, Vite 7, Tailwind CSS 4, React Router 7, Axios
- **Dev services:** Docker Compose (MySQL, Redis, phpMyAdmin, Mailpit)

## Project structure

```
unisphere/
├── backend/                 Laravel REST API
│   ├── app/
│   │   ├── Enums/Role.php
│   │   ├── Http/Controllers/{Admin,Academic,Classroom,Finance}/
│   │   ├── Http/Middleware/  EnsureRole, EnsureClassroomMember
│   │   ├── Models/           24 Eloquent models
│   │   ├── Notifications/
│   │   └── Services/         GradeService, ResultSyncService (classroom -> results)
│   ├── config/unisphere.php  grading scale, weights, attendance minimum, credit limit
│   ├── database/migrations/  full schema
│   ├── database/seeders/     demo university with users for every role
│   ├── routes/api.php
│   └── tests/
├── frontend/                React + Vite PWA
│   └── src/{api,context,components,pages}
├── docs/                    project proposal
└── docker-compose.yml
```

## Getting started

### Requirements

PHP 8.2+ with `pdo_mysql`, Composer 2, Node.js 20+, and MySQL 8 (or Docker).

### 1. Start the database

```bash
docker compose up -d        # MySQL :3306, phpMyAdmin :8080, Redis :6379, Mailpit :8025
```

Or create a MySQL database called `unisphere` yourself.

### 2. Backend (API on http://localhost:8000)

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

### 3. Frontend (app on http://localhost:5173)

```bash
cd frontend
npm install
npm run dev
```

In development, Vite forwards `/api` and `/storage` to `http://localhost:8000`.

### Demo accounts

Every demo account's password is `password`. The login page also has one-click buttons for them in development.

| Role | Email |
| --- | --- |
| Student | student@unisphere.test |
| Teacher | teacher@unisphere.test |
| Department Head | head.cse@unisphere.test |
| Registrar | registrar@unisphere.test |
| Accounts Officer | accounts@unisphere.test |
| Super Admin | admin@unisphere.test |

The seeder creates the CSE department, 5 Fall 2026 courses, 3 teachers and 25 students. The students are enrolled in 4 classes and each has a tuition invoice.

## How grades flow

1. The teacher creates assignments and quizzes, each tagged with a category: `assignment`, `quiz`, `mid` or `final`.
2. Attendance sessions supply the `attendance` category.
3. The **gradebook** averages each category and applies the section's weights. The defaults are attendance 10, assignment 10, quiz 10, mid 20 and final 50, and can be changed in `config/unisphere.php` or per section.
4. **Send to official results** saves the totals, letter grades and grade points as draft results.
5. The department head or registrar **publishes** them, and each student's CGPA is recalculated.

The grading scale is the UGC Bangladesh uniform scale: A+ (80+) = 4.00 down to D (40–44) = 2.00, and below 40 is F.

## API overview

All endpoints are under `/api` and use `Authorization: Bearer <token>`.

| Method | Endpoint | Who |
| --- | --- | --- |
| POST | `/auth/login` | everyone |
| GET | `/auth/me`, `/dashboard`, `/notifications` | signed in |
| GET/POST | `/classes`, `/classes/join` | members / students |
| GET/POST | `/classes/{id}/posts` · `/materials` · `/assignments` · `/quizzes` | class members |
| GET | `/classes/{id}/gradebook` · POST `/gradebook/sync` | teacher |
| POST | `/classes/{id}/results/publish` | dept. head, registrar |
| GET/POST | `/classes/{id}/attendance` · POST `/attendance/check-in` | teacher / student |
| POST | `/assignments/{id}/submit` · PATCH `/submissions/{id}/grade` | student / teacher |
| POST | `/quizzes/{id}/start` · `/submit` | student |
| GET/POST | `/registration/offered` · `/registration` · `/results/mine` | student |
| GET/PATCH | `/registration/pending` · `/registration/{id}/decide` | advisor, dept. head, registrar |
| GET/POST | `/invoices` · `/invoices/{id}/pay` · `/invoices/{id}/cash` | student / accounts |
| GET/POST | `/notices`, `/complaints` | everyone (posting notices: staff) |
| CRUD | `/admin/users` · `departments` · `programs` · `semesters` · `courses` · `sections` | registrar, super admin |

See `backend/routes/api.php` for the full list.

## Tests

```bash
cd backend && php artisan test
```

The tests use an in-memory SQLite database. They cover login, role protection, classroom membership, and the full flow from an assignment grade to the gradebook. CI runs the backend tests and the frontend build on every push.

## Roadmap (stretch goals)

- [ ] Camera QR scanner for attendance (`html5-qrcode`)
- [ ] Live classes via embedded Jitsi Meet
- [ ] Real-time stream updates (Laravel Reverb) and Firebase push notifications
- [ ] Library and transport (live bus GPS) modules
- [ ] Real bKash Tokenized Checkout and SSLCommerz integration
- [ ] AI FAQ chatbot and at-risk student prediction
- [ ] Native mobile app (Flutter)

## Author

**Nayan Ray** · [nayanray.com](https://nayanray.com) · [github.com/dev-nayanray](https://github.com/dev-nayanray)

## License

MIT
