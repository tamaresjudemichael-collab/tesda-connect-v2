# TESDA Connect V2 — Phase 2A Patch

This patch adds attendance recording and makes the existing course competency progress visible in a confirmed learner's portal.

## Included changes

- Adds `AttendanceRecord` model and `attendance_records` migration.
- Adds one attendance record per confirmed enrollment per date, with status (`present`, `absent`, `late`), optional remarks, and recording user.
- Adds the `/attendance` route and staff navigation item for administrators and focal persons.
- Restricts attendance batch selection and writes to the user's institution scope; only confirmed applications assigned to the chosen batch are eligible.
- Adds attendance summaries and recent attendance records to the learner portal, scoped to the learner's own confirmed application.
- Adds actual read-only competency-task statuses and completion percentage to the learner portal, with no grades or numerical assessment scores.
- Adds focused feature tests for attendance authorization/recording and learner progress display.

## Apply safely

1. Back up the project folder and database.
2. Extract the ZIP contents into the existing `C:\xampp\htdocs\tesda-connect-v2` folder, preserving the relative paths.
3. From PowerShell, run:

   ```powershell
   cd C:\xampp\htdocs\tesda-connect-v2
   php artisan migrate
   php artisan test --filter=AttendanceManagementTest
   php artisan test --filter=LearnerPortalTrainingProgressTest
   php artisan test
   ```

4. Do not run `migrate:fresh` or reset the database.

## Verification status

The included PHP files and the PHP front matter in the Volt Blade files were syntax-checked while building this patch. Laravel/Pest runtime tests were not executable in the build environment because the project `vendor/` directory is not included in the source ZIP. Run the tests in the local project and review any failures before treating this tranche as verified.

## Scope notes

Attendance is stored per enrollment application and calendar date; multiple sessions for the same learner on the same date are not represented separately in this first pass. Attendance is recorded manually by an administrator or focal person. Offline queueing, synchronization, certificate generation, announcements management, and report exports remain separate work items.
