<?php

use App\Support\SystemBackupArchive;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

it('creates a verified full archive with a database snapshot, private files, and a manifest', function () {
    $root = storage_path('framework/testing/system-backup-'.uniqid('', true));
    $backupDirectory = $root.DIRECTORY_SEPARATOR.'backups';
    $privateDirectory = $root.DIRECTORY_SEPARATOR.'private';
    File::ensureDirectoryExists($privateDirectory.DIRECTORY_SEPARATOR.'learner-documents'.DIRECTORY_SEPARATOR.'42');

    $sourcePath = $root.DIRECTORY_SEPARATOR.'source.sqlite';
    File::ensureDirectoryExists($root);
    $source = new PDO('sqlite:'.$sourcePath);
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $source->exec('CREATE TABLE backup_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $source->exec("INSERT INTO backup_probe (value) VALUES ('system backup works')");
    File::put($privateDirectory.DIRECTORY_SEPARATOR.'learner-documents'.DIRECTORY_SEPARATOR.'42'.DIRECTORY_SEPARATOR.'birth-certificate.pdf', 'private learner document');
    File::ensureDirectoryExists($privateDirectory.DIRECTORY_SEPARATOR.'backups');
    File::put($privateDirectory.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'do-not-include.txt', 'exclude nested backup folder');

    try {
        $result = app(SystemBackupArchive::class)->create($source, $backupDirectory, $privateDirectory);

        expect($result['database_integrity'])->toBe('ok')
            ->and($result['private_file_count'])->toBe(1)
            ->and($result['size_bytes'])->toBeGreaterThan(0)
            ->and($result['manifest_sha256'])->toMatch('/\A[a-f0-9]{64}\z/')
            ->and(File::isFile($result['path']))->toBeTrue();

        $verified = app(SystemBackupArchive::class)->verifyArchive($result['path']);
        expect($verified['manifest']['format'])->toBe('tesda-connect-system-backup')
            ->and($verified['manifest']['database_integrity_check'])->toBe('ok')
            ->and($verified['manifest']['private_file_count'])->toBe(1);

        $archive = new ZipArchive();
        expect($archive->open($result['path']))->toBeTrue();
        expect($archive->getFromName('private-storage/learner-documents/42/birth-certificate.pdf'))->toBe('private learner document');
        expect($archive->locateName('private-storage/backups/do-not-include.txt'))->toBeFalse();
        $databaseEntry = $verified['manifest']['database_entry'];
        $databaseBytes = $archive->getFromName($databaseEntry);
        expect(is_string($databaseBytes))->toBeTrue();
        $archive->close();

        $extractedDatabase = $root.DIRECTORY_SEPARATOR.'extracted.sqlite';
        File::put($extractedDatabase, $databaseBytes);
        $restored = new PDO('sqlite:'.$extractedDatabase);
        expect($restored->query('PRAGMA integrity_check')->fetchColumn())->toBe('ok')
            ->and($restored->query('SELECT value FROM backup_probe')->fetchColumn())->toBe('system backup works');
        $restored = null;
    } finally {
        $source = null;
        File::deleteDirectory($root);
    }
})->skip(! class_exists(ZipArchive::class) || ! in_array('sqlite', PDO::getAvailableDrivers(), true), 'Requires PHP zip and pdo_sqlite extensions.');

it('rejects a full backup archive when a listed file no longer matches its checksum', function () {
    $root = storage_path('framework/testing/system-backup-tamper-'.uniqid('', true));
    $backupDirectory = $root.DIRECTORY_SEPARATOR.'backups';
    $privateDirectory = $root.DIRECTORY_SEPARATOR.'private';
    File::ensureDirectoryExists($root);
    $source = new PDO('sqlite:'.$root.DIRECTORY_SEPARATOR.'source.sqlite');
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $source->exec('CREATE TABLE backup_probe (id INTEGER PRIMARY KEY)');

    try {
        $result = app(SystemBackupArchive::class)->create($source, $backupDirectory, $privateDirectory);
        $archive = new ZipArchive();
        expect($archive->open($result['path']))->toBeTrue();
        $manifest = json_decode($archive->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $entry = $manifest['database_entry'];
        $archive->deleteName($entry);
        $archive->addFromString($entry, 'tampered database bytes');
        $archive->close();

        expect(fn () => app(SystemBackupArchive::class)->verifyArchive($result['path']))
            ->toThrow(RuntimeException::class, 'checksum');
    } finally {
        $source = null;
        File::deleteDirectory($root);
    }
})->skip(! class_exists(ZipArchive::class) || ! in_array('sqlite', PDO::getAvailableDrivers(), true), 'Requires PHP zip and pdo_sqlite extensions.');
