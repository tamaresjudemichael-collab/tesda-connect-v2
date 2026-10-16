<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use ZipArchive;

class SystemBackupArchive
{
    /**
     * Create a portable archive containing a verified SQLite snapshot and the
     * application's private local files (learner documents and course materials).
     *
     * @return array{name:string,path:string,size_bytes:int,created_at:string,database_integrity:string,private_file_count:int,manifest_sha256:string}
     */
    public function create(PDO $source, string $backupDirectory, string $privateStorageDirectory): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Full system backups require the PHP zip extension. Enable extension=zip in the active php.ini and restart Apache.');
        }

        File::ensureDirectoryExists($backupDirectory);
        $workDirectory = rtrim($backupDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.system-backup-'.Str::random(12);
        File::ensureDirectoryExists($workDirectory);

        $filename = 'tesda-connect-system-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.zip';
        $archivePath = rtrim($backupDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        $databasePath = null;
        $zip = null;

        try {
            $database = app(SqliteDatabaseBackup::class)->create($source, $workDirectory);
            $databasePath = $database['path'];

            $manifestFiles = [];
            $privateFileCount = 0;
            $zip = new ZipArchive();
            $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL);
            if ($opened !== true) {
                throw new RuntimeException('Could not create the full system backup archive.');
            }

            $databaseEntry = 'database/'.$database['name'];
            if (! $zip->addFile($databasePath, $databaseEntry)) {
                throw new RuntimeException('Could not add the database snapshot to the backup archive.');
            }
            $databaseHash = hash_file('sha256', $databasePath);
            if (! is_string($databaseHash)) {
                throw new RuntimeException('Could not calculate the database snapshot checksum.');
            }
            $manifestFiles[$databaseEntry] = $databaseHash;

            if (File::isDirectory($privateStorageDirectory)) {
                foreach (File::allFiles($privateStorageDirectory) as $file) {
                    $relativePath = str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen(rtrim($privateStorageDirectory, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR.'\\/'));
                    if ($relativePath === '' || str_starts_with($relativePath, 'backups/')) {
                        continue;
                    }

                    $entry = 'private-storage/'.$relativePath;
                    if (! $zip->addFile($file->getPathname(), $entry)) {
                        throw new RuntimeException('Could not add a private storage file to the backup archive.');
                    }
                    $fileHash = hash_file('sha256', $file->getPathname());
                    if (! is_string($fileHash)) {
                        throw new RuntimeException('Could not calculate a private storage file checksum.');
                    }
                    $manifestFiles[$entry] = $fileHash;
                    $privateFileCount++;
                }
            }

            $manifest = [
                'format' => 'tesda-connect-system-backup',
                'format_version' => 1,
                'created_at' => now()->toIso8601String(),
                'database_entry' => $databaseEntry,
                'database_integrity_check' => $database['integrity'],
                'private_storage_entry_prefix' => 'private-storage/',
                'private_file_count' => $privateFileCount,
                'files' => $manifestFiles,
                'restore_note' => 'Restore only into a maintenance window after verifying this archive and taking a separate backup of the current system.',
            ];
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (! $zip->addFromString('manifest.json', $manifestJson)) {
                throw new RuntimeException('Could not write the backup manifest.');
            }
            if (! $zip->close()) {
                $zip = null;
                throw new RuntimeException('Could not finalize the backup archive.');
            }
            $zip = null;

            $verified = $this->verifyArchive($archivePath);

            return [
                'name' => $filename,
                'path' => $archivePath,
                'size_bytes' => File::size($archivePath),
                'created_at' => now()->format('M d, Y h:i A'),
                'database_integrity' => $database['integrity'],
                'private_file_count' => $privateFileCount,
                'manifest_sha256' => $verified['manifest_sha256'],
            ];
        } catch (\Throwable $exception) {
            if ($zip instanceof ZipArchive) {
                $zip->close();
            }
            if (File::isFile($archivePath)) {
                File::delete($archivePath);
            }
            throw $exception;
        } finally {
            if ($databasePath && File::isFile($databasePath)) {
                File::delete($databasePath);
            }
            File::deleteDirectory($workDirectory);
        }
    }

    /**
     * Validate the ZIP structure and every file checksum recorded in manifest.json.
     *
     * @return array{manifest:array<string,mixed>,manifest_sha256:string}
     */
    public function verifyArchive(string $archivePath): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Full system backup verification requires the PHP zip extension.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new RuntimeException('The system backup is not a valid, consistent ZIP archive.');
        }

        try {
            $manifestJson = $zip->getFromName('manifest.json');
            if (! is_string($manifestJson)) {
                throw new RuntimeException('The system backup manifest is missing.');
            }
            $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 'tesda-connect-system-backup'
                || ($manifest['format_version'] ?? null) !== 1
                || ! is_array($manifest['files'] ?? null)
                || ! isset($manifest['database_entry'], $manifest['database_integrity_check'])) {
                throw new RuntimeException('The system backup manifest is invalid or unsupported.');
            }

            foreach ($manifest['files'] as $entry => $expectedHash) {
                if (! is_string($entry) || ! is_string($expectedHash) || str_starts_with($entry, '/') || str_contains($entry, '../') || str_contains($entry, '..\\') || str_contains($entry, '\\') || preg_match('/\A[A-Za-z]:/', $entry) === 1) {
                    throw new RuntimeException('The system backup manifest contains an invalid entry.');
                }
                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw new RuntimeException('A file listed in the system backup manifest is missing.');
                }
                $context = hash_init('sha256');
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        fclose($stream);
                        throw new RuntimeException('Could not read a file from the system backup archive.');
                    }
                    hash_update($context, $chunk);
                }
                fclose($stream);
                if (! hash_equals($expectedHash, hash_final($context))) {
                    throw new RuntimeException('A file checksum does not match the system backup manifest.');
                }
            }

            if (! isset($manifest['files'][$manifest['database_entry']]) || ($manifest['database_integrity_check'] ?? null) !== 'ok') {
                throw new RuntimeException('The system backup does not contain a verified database snapshot.');
            }

            return ['manifest' => $manifest, 'manifest_sha256' => hash('sha256', $manifestJson)];
        } finally {
            $zip->close();
        }
    }
}
