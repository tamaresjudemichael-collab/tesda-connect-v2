<?php

use App\Support\SqliteDatabaseBackup;
use Illuminate\Support\Facades\File;


uses(Tests\TestCase::class);
 

it('creates a verified sqlite snapshot in the requested backup folder', function () {
    $root = storage_path('framework/testing/sqlite-backup-'.uniqid('', true));
    $backupDirectory = $root.DIRECTORY_SEPARATOR.'backups';
    File::ensureDirectoryExists($root);

    $sourcePath = $root.DIRECTORY_SEPARATOR.'source.sqlite';
    $source = new PDO('sqlite:'.$sourcePath);
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $source->exec('CREATE TABLE backup_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $source->exec("INSERT INTO backup_probe (value) VALUES ('backup works')");

    $result = null;

    try {
        $result = app(SqliteDatabaseBackup::class)->create($source, $backupDirectory);

        expect($result['integrity'])->toBe('ok');
        expect(str_ends_with($result['name'], '.sqlite'))->toBeTrue();
        expect(File::isFile($result['path']))->toBeTrue();
        expect($result['size_bytes'])->toBeGreaterThan(0);
        expect(str_starts_with($result['path'], $backupDirectory))->toBeTrue();

        $backup = new PDO('sqlite:'.$result['path']);
        $backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        expect($backup->query('PRAGMA integrity_check')->fetchColumn())->toBe('ok');
        expect($backup->query('SELECT value FROM backup_probe')->fetchColumn())->toBe('backup works');
        $backup = null;
    } finally {
        $source = null;
        File::deleteDirectory($root);
    }
});
