# Student Announcement Read-State Test Fix

The current dashboard tracks a unique announcement **impression** in `announcement_views.viewed_at`. That must not mark the announcement as read; the `read_at` column stays null until the student selects **Mark as read**. The test's old expectation that no view row exists is stale and conflicts with unique viewership tracking.

## Install

1. Extract this ZIP to the project root (`C:\xampp\htdocs\tesda-connect-v2`), next to `artisan`.
2. Run PowerShell from that project root:

```powershell
powershell -ExecutionPolicy Bypass -File .\Apply-StudentAnnouncementReadStateTestFix.ps1
php artisan test --filter=StudentScholarWorkspaceTest
```

The script only updates the one stale assertion in `tests/Feature/StudentScholarWorkspaceTest.php`. It backs up the test first under `storage/app/patch-backups/`. It does not alter application behavior or require a migration.
