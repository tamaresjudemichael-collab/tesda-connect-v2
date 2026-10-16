# Patch 4 — Full System Backup and Restore Readiness

## Changes

- Adds `App\Support\SystemBackupArchive` to bundle a verified SQLite snapshot and private local files into a ZIP archive.
- Writes `manifest.json` with SHA-256 hashes and verifies the ZIP structure and each payload checksum before reporting success.
- Adds **Create full backup** to the administrator System Health page while retaining the existing SQLite-only backup action.
- Lists/downloads both `tesda-connect-db-*.sqlite` and `tesda-connect-system-*.zip` files, restricted to active administrators.
- Adds unit coverage for valid archive creation, private-file inclusion/exclusion, extracted database integrity, and checksum-tampering rejection, plus a ZIP download feature test.
- Includes a manual, non-destructive restore rehearsal runbook at `docs/BACKUP-RESTORE.md`.
- Carries forward Patch 3's learner document status and resubmission guidance.

## Files in this patch

- `app/Support/SystemBackupArchive.php` (new)
- `app/Http/Controllers/Admin/DownloadSystemBackupController.php`
- `resources/views/livewire/system/health.blade.php`
- `routes/admin-oversight.php`
- `tests/Unit/SystemBackupArchiveTest.php` (new)
- `tests/Feature/AdministratorBackupDownloadTest.php`
- `tests/Feature/AdministratorHealthBackupButtonTest.php`
- `docs/BACKUP-RESTORE.md` (new)
- `resources/views/livewire/learner/registration.blade.php` (Patch 3)
- `tests/Feature/StudentScholarWorkspaceTest.php` (Patch 3)

## Apply safely

1. Back up the current project and database first.
2. Extract this ZIP into a temporary folder and compare each listed file with the same path in your project.
3. Copy/merge the listed files into `C:\xampp\htdocs\tesda-connect-v2`; do not replace unrelated project files.
4. Ensure the XAMPP PHP `php.ini` has `extension=zip` enabled, then restart Apache.
5. Run:

```powershell
php artisan test --filter=SystemBackupArchiveTest
php artisan test --filter=AdministratorBackupDownloadTest
php artisan test --filter=AdministratorHealthBackupButtonTest
php artisan test --filter=StudentScholarWorkspaceTest
php artisan test
```

The archive tests require PHP `zip` and `pdo_sqlite`. The ZIP feature download test uses a small fixture file; it does not create a real database backup. Full suite output must be reviewed on your local project.

## Safety note

This patch does not restore over the live database automatically. Follow `docs/BACKUP-RESTORE.md` to rehearse restoration against a separate test copy first.
