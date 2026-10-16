<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadSystemBackupController
{
    public function __invoke(Request $request, string $filename): BinaryFileResponse
    {
        abort_unless($request->user()?->role === 'administrator', 403);

        // Accept only the application's backup naming conventions. This prevents
        // path traversal and blocks downloads of arbitrary files from storage.
        abort_unless(
            preg_match('/\\Atesda-connect-(?:db-[A-Za-z0-9_-]+\\.sqlite|system-[A-Za-z0-9_-]+\\.zip)\\z/D', $filename) === 1
            && basename($filename) === $filename,
            404
        );

        $backupDirectory = storage_path('app/backups');
        $path = $backupDirectory.DIRECTORY_SEPARATOR.$filename;
        abort_unless(File::isFile($path), 404);

        $realDirectory = realpath($backupDirectory);
        $realPath = realpath($path);
        abort_unless(
            $realDirectory !== false && $realPath !== false && dirname($realPath) === $realDirectory,
            404
        );

        return response()->download($realPath, $filename, [
            'Content-Type' => str_ends_with($filename, '.zip') ? 'application/zip' : 'application/vnd.sqlite3',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
