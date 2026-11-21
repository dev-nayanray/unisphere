# UniSphere permission matrix

Who can call each API route (all under `/api`). ✓ = allowed, blank = 403 Forbidden. Guests get 401 on every route below.

This table mirrors `backend/tests/Feature/PermissionMatrixTest.php`, which calls every route as every role. If you change a permission, update both.

**Fixture assumptions:** *Teacher* teaches the class and advises the student; *Dept. Head* heads the course's department; *Student* is enrolled; *Registrar* wrote the notice; the student filed the complaint and owns the invoice. Other teachers, heads and students get the same answer as a non-member (403).

Two extra rules apply everywhere: deactivated accounts get 403 (`account_inactive`), and accounts with a temporary password get 403 (`password_change_required`) on everything except `auth/me`, `auth/logout` and `auth/password`.


## Account

| Route | Super Admin | Registrar | Dept. Head | Teacher | Accounts | Student |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `GET auth/me` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `POST auth/logout` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `PUT auth/password` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `PUT auth/profile` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `POST auth/avatar` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `GET auth/activity` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `GET dashboard` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `GET notifications` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `POST notifications/read-all` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

## Notices & help desk

| Route | Super Admin | Registrar | Dept. Head | Teacher | Accounts | Student |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `GET notices` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `GET notices/{notice}` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `POST notices` | ✓ | ✓ | ✓ | ✓ |  |  |
| `DELETE notices/{notice}` | ✓ | ✓ |  |  |  |  |
| `GET complaints` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `POST complaints` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `GET complaints/{complaint}` | ✓ | ✓ |  |  |  | ✓ |
| `PUT complaints/{complaint}` | ✓ | ✓ |  |  |  |  |
| `PATCH complaints/{complaint}` | ✓ | ✓ |  |  |  |  |

## Virtual classroom

| Route | Super Admin | Registrar | Dept. Head | Teacher | Accounts | Student |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `GET classes` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `POST classes/join` | ✓ |  |  |  |  | ✓ |
| `GET classes/{section}` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `GET classes/{section}/people` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `POST classes/{section}/join-code` | ✓ | ✓ |  | ✓ |  |  |
| `GET classes/{section}/posts` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `POST classes/{section}/posts` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `GET classes/{section}/materials` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `POST classes/{section}/materials` | ✓ | ✓ |  | ✓ |  |  |
| `GET classes/{section}/assignments` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `POST classes/{section}/assignments` | ✓ | ✓ |  | ✓ |  |  |
| `GET classes/{section}/quizzes` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `POST classes/{section}/quizzes` | ✓ | ✓ |  | ✓ |  |  |
| `GET classes/{section}/gradebook` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `GET classes/{section}/gradebook/summary` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `PUT classes/{section}/gradebook/scheme` | ✓ | ✓ |  | ✓ |  |  |
| `POST classes/{section}/gradebook/sync` | ✓ | ✓ |  | ✓ |  |  |
| `GET classes/{section}/attendance` | ✓ | ✓ |  | ✓ |  |  |
| `POST classes/{section}/attendance` | ✓ | ✓ |  | ✓ |  |  |
| `GET classes/{section}/attendance/report` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `GET classes/{section}/results` | ✓ | ✓ | ✓ | ✓ |  |  |
| `POST classes/{section}/results/publish` | ✓ | ✓ | ✓ |  |  |  |
| `PATCH posts/{post}` | ✓ | ✓ |  | ✓ |  |  |
| `DELETE posts/{post}` | ✓ | ✓ |  | ✓ |  |  |
| `POST posts/{post}/comments` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `DELETE materials/{material}` | ✓ | ✓ |  | ✓ |  |  |
| `GET assignments/{assignment}` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `PATCH assignments/{assignment}` | ✓ | ✓ |  | ✓ |  |  |
| `DELETE assignments/{assignment}` | ✓ | ✓ |  | ✓ |  |  |
| `POST assignments/{assignment}/submit` |  |  |  |  |  | ✓ |
| `POST assignments/{assignment}/grades` | ✓ | ✓ |  | ✓ |  |  |
| `PATCH submissions/{submission}/grade` | ✓ | ✓ |  | ✓ |  |  |
| `GET quizzes/{quiz}` | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `POST quizzes/{quiz}/start` |  |  |  |  |  | ✓ |
| `POST quizzes/{quiz}/submit` |  |  |  |  |  | ✓ |
| `PATCH quiz-attempts/{attempt}/grade` | ✓ | ✓ |  | ✓ |  |  |

## Attendance

| Route | Super Admin | Registrar | Dept. Head | Teacher | Accounts | Student |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `GET attendance-sessions/{session}` | ✓ | ✓ |  | ✓ |  |  |
| `PUT attendance-sessions/{session}/records` | ✓ | ✓ |  | ✓ |  |  |
| `POST attendance/check-in` |  |  |  |  |  | ✓ |

## Routine

| Route | Super Admin | Registrar | Dept. Head | Teacher | Accounts | Student |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `GET routine/me` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `GET routine/me.ics` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `GET routine/rooms` | ✓ | ✓ | ✓ | ✓ |  |  |
| `GET routine/rooms/{room}` | ✓ | ✓ | ✓ | ✓ |  |  |

## Registration & results

| Route | Super Admin | Registrar | Dept. Head | Teacher | Accounts | Student |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `GET registration/offered` | ✓ |  |  |  |  | ✓ |
| `GET registration/mine` | ✓ |  |  |  |  | ✓ |
| `POST registration` | ✓ |  |  |  |  | ✓ |
| `DELETE registration/{registration}` |  |  |  |  |  | ✓ |
| `GET results/mine` | ✓ |  |  |  |  | ✓ |
| `GET registration/pending` | ✓ | ✓ | ✓ | ✓ |  |  |
| `PATCH registration/{registration}/decide` | ✓ | ✓ | ✓ | ✓ |  |  |
| `PATCH registration/decide-many` | ✓ | ✓ | ✓ | ✓ |  |  |
| `POST registration/force` | ✓ | ✓ |  |  |  |  |

## Fees

| Route | Super Admin | Registrar | Dept. Head | Teacher | Accounts | Student |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `GET invoices` | ✓ |  |  |  | ✓ | ✓ |
| `GET invoices/{invoice}` | ✓ |  |  |  | ✓ | ✓ |
| `POST invoices/{invoice}/pay` |  |  |  |  |  | ✓ |
| `POST invoices` | ✓ |  |  |  | ✓ |  |
| `PATCH invoices/{invoice}` | ✓ |  |  |  | ✓ |  |
| `POST invoices/{invoice}/cash` | ✓ |  |  |  | ✓ |  |

## Lookups

| Route | Super Admin | Registrar | Dept. Head | Teacher | Accounts | Student |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `GET semesters` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `GET departments` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `GET programs` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

## University administration

| Route | Super Admin | Registrar | Dept. Head | Teacher | Accounts | Student |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `POST admin/users/{user}/reset-password` | ✓ | ✓ |  |  |  |  |
| `POST admin/semesters/{semester}/make-current` | ✓ | ✓ |  |  |  |  |
| `GET admin/users` | ✓ | ✓ |  |  |  |  |
| `GET admin/courses` | ✓ | ✓ |  |  |  |  |
| `GET admin/sections` | ✓ | ✓ |  |  |  |  |
| `POST admin/users` | ✓ | ✓ |  |  |  |  |
| `GET · PUT · PATCH · DELETE admin/users/{user}` | ✓ | ✓ |  |  |  |  |
| `POST admin/departments` | ✓ | ✓ |  |  |  |  |
| `GET · PUT · PATCH · DELETE admin/departments/{department}` | ✓ | ✓ |  |  |  |  |
| `POST admin/programs` | ✓ | ✓ |  |  |  |  |
| `GET · PUT · PATCH · DELETE admin/programs/{program}` | ✓ | ✓ |  |  |  |  |
| `POST admin/semesters` | ✓ | ✓ |  |  |  |  |
| `GET · PUT · PATCH · DELETE admin/semesters/{semester}` | ✓ | ✓ |  |  |  |  |
| `POST admin/courses` | ✓ | ✓ |  |  |  |  |
| `GET · PUT · PATCH · DELETE admin/courses/{course}` | ✓ | ✓ |  |  |  |  |
| `POST admin/sections` | ✓ | ✓ |  |  |  |  |
| `GET · PUT · PATCH · DELETE admin/sections/{section}` | ✓ | ✓ |  |  |  |  |

Super admins pass every policy check except personal actions: submitting work, taking a quiz, checking in, paying and dropping a course.
