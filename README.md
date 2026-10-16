# TESDA Connect V2 — Panel Revision Patch 2

## Purpose

Adds a learner-facing enrollment progress timeline and a next-step panel to the existing Student / Scholar Dashboard. The panel displays the signed-in learner's application reference, recorded status transitions and reasons, current status guidance, and a direct link to continue registration or revise a returned application.

## Files

- `resources/views/livewire/learner/portal.blade.php`
- `tests/Feature/StudentScholarWorkspaceTest.php`

## Apply safely

1. Back up your current project and SQLite database.
2. Compare the included files with the same paths in your current project before replacing anything.
3. Copy the files to the matching paths in `C:\xampp\htdocs\tesda-connect-v2` only after reviewing the diff.
4. No migration is required; this uses the existing `enrollment_status_histories` table and `statusHistories` relationship.
5. Run:

```powershell
php artisan test --filter=StudentScholarWorkspaceTest
php artisan test --filter=CourseLearningMaterialsTest
php artisan test --filter=InstitutionScopeTest
php artisan test
```

## Validation status

PHP syntax checks passed for both changed files in the patch workspace. Laravel tests could not be run against the uploaded project snapshot because that snapshot omits `bootstrap/app.php`. Run the commands above in your actual project to verify behavior.

## Scope / privacy

The dashboard's application query remains scoped to the authenticated learner's own `learner_user_id`. Status reasons are shown only for that learner's application. No staff permissions, enrollment transitions, or database schema are changed.
