# TESDA Connect V2 — Backup and Restore Runbook

## What the full system backup contains

From **System Health → Create full backup**, an administrator can create a ZIP archive in `storage/app/backups`. It contains:

- `database/tesda-connect-db-....sqlite` — a committed SQLite snapshot created with `VACUUM INTO` and checked using `PRAGMA integrity_check`.
- `private-storage/` — files found under Laravel's `storage/app/private` directory, including learner documents and course materials. A nested `backups/` folder is excluded to avoid recursively archiving old backups.
- `manifest.json` — format version, creation time, database entry, private-file count, and SHA-256 checksums for every archived payload file.

The app verifies the ZIP structure and payload checksums before reporting success. This is archive verification, not a guarantee that a production restore has been rehearsed successfully.

## Before applying this patch

1. Back up the project folder and the current `database/database.sqlite`.
2. Apply the files in this patch, preserving any local edits.
3. Confirm the PHP configuration used by XAMPP has `extension=zip` enabled; restart Apache after changing `php.ini`.
4. Run the focused tests and then the full suite.

## Creating and storing a backup

1. Sign in as an active administrator and open **System Health**.
2. Click **Create full backup** and wait for the success message.
3. Download the `.zip` file using the provided link.
4. Copy the downloaded archive to a separate physical device or approved off-site location. A backup that exists only on the application server can be lost with that server.
5. Keep access to backups restricted: the archive contains sensitive learner information.

## Safe restore rehearsal (do not overwrite production first)

1. Put the application into a maintenance window and take a separate backup of the current database and private files.
2. Copy the archive to an isolated test folder. Do not extract untrusted archives directly into the live application directory.
3. Extract the ZIP using a trusted archive tool. Review `manifest.json` and verify the archive's file list/checksums against it before using the files.
4. Run `PRAGMA integrity_check;` against the extracted SQLite file using a SQLite client. The result must be `ok`.
5. Restore the extracted database to a **test copy** of the app's configured SQLite database and place `private-storage/` contents under the test copy's `storage/app/private/` directory, preserving relative paths.
6. Run migrations/status checks and focused learner-document/course-material download tests against the test copy. Confirm the restored documents are present and access-controlled.
7. Record the rehearsal date, archive filename, result, and any missing files. Only use the same procedure for production after a successful rehearsal and with a verified pre-restore backup.

## Important limits

- This patch creates and verifies backups; it intentionally does not provide a one-click restore endpoint because replacing the active database and private files is destructive.
- It currently supports SQLite. It does not back up `.env`, source code, vendor dependencies, browser IndexedDB queues, or remote/object storage.
- If uploads are stored outside `storage/app/private`, add that configured storage location to the backup plan before relying on this archive.
- Schedule and rehearse backups regularly, and retain at least one copy outside the server.
