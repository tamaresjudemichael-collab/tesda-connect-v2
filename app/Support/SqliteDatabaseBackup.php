<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

class SqliteDatabaseBackup
{
    /**
     * Create a consistent SQLite snapshot and verify its integrity before returning it.
     *
     * @return array{name:string,path:string,size_bytes:int,created_at:string,integrity:string}
     */
    public function create(PDO $source, string $backupDirectory): array
    {
        File::ensureDirectoryExists($backupDirectory);

        $filename = 'tesda-connect-db-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.sqlite';
        $path = rtrim($backupDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;

        try {
            $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // VACUUM INTO produces a consistent snapshot of committed SQLite data,
            // including data that may still be present in a WAL file.
            $source->exec('VACUUM INTO '.$source->quote($path));

            if (! File::isFile($path) || File::size($path) <= 0) {
                throw new RuntimeException('SQLite did not create a non-empty backup file.');
            }

            $backup = new PDO('sqlite:'.$path);
            $backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $integrity = $backup->query('PRAGMA integrity_check')->fetchColumn();
            $backup = null;

            if ($integrity !== 'ok') {
                throw new RuntimeException('The SQLite backup failed its integrity check.');
            }

            return [
                'name' => $filename,
                'path' => $path,
                'size_bytes' => File::size($path),
                'created_at' => now()->format('M d, Y h:i A'),
                'integrity' => 'ok',
            ];
        } catch (Throwable $exception) {
            if (File::isFile($path)) {
                File::delete($path);
            }

            throw $exception;
        }
    }
}
