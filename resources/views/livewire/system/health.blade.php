<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use App\Support\ActivityLogger;
use App\Support\SqliteDatabaseBackup;
use App\Support\SystemBackupArchive;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('System Health');

state([
    'backupResult' => null,
    'backupError' => null,
    'systemBackupResult' => null,
    'systemBackupError' => null,
]);

$createBackup = function (): void {
    abort_unless(auth()->check() && auth()->user()->role === 'administrator', 403);

    $this->backupResult = null;
    $this->backupError = null;

    try {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Automatic backup currently supports SQLite databases only.');
        }

        $databasePath = (string) $connection->getDatabaseName();
        if ($databasePath === '' || $databasePath === ':memory:' || ! File::isFile($databasePath)) {
            throw new RuntimeException('A persistent SQLite database file could not be found at the configured database path.');
        }

        $result = app(SqliteDatabaseBackup::class)->create(
            $connection->getPdo(),
            storage_path('app/backups')
        );

        $this->backupResult = [
            'name' => $result['name'],
            'path' => $result['path'],
            'size' => $result['size_bytes'],
            'created_at' => $result['created_at'],
            'integrity' => $result['integrity'],
        ];

        try {
            ActivityLogger::record(
                'system_backup_created',
                'Created and integrity-checked a SQLite database backup.',
                null,
                ['file_name' => $result['name'], 'size_bytes' => $result['size_bytes'], 'integrity_check' => $result['integrity']]
            );
        } catch (Throwable $loggingException) {
            report($loggingException);
        }

        unset($this->health);
    } catch (Throwable $exception) {
        report($exception);
        $this->backupError = 'Backup failed. Check the SQLite database path, backup-folder permissions, and Laravel logs for details.';
    }
};

$createSystemBackup = function (): void {
    abort_unless(auth()->check() && auth()->user()->role === 'administrator', 403);

    $this->systemBackupResult = null;
    $this->systemBackupError = null;

    try {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Full system backup currently supports SQLite databases only.');
        }

        $databasePath = (string) $connection->getDatabaseName();
        if ($databasePath === '' || $databasePath === ':memory:' || ! File::isFile($databasePath)) {
            throw new RuntimeException('A persistent SQLite database file could not be found at the configured database path.');
        }

        $result = app(SystemBackupArchive::class)->create(
            $connection->getPdo(),
            storage_path('app/backups'),
            storage_path('app/private')
        );

        $this->systemBackupResult = $result;
        try {
            ActivityLogger::record(
                'system_backup_created',
                'Created and verified a full system backup containing the SQLite database and private local files.',
                null,
                [
                    'file_name' => $result['name'],
                    'size_bytes' => $result['size_bytes'],
                    'database_integrity_check' => $result['database_integrity'],
                    'private_file_count' => $result['private_file_count'],
                    'manifest_sha256' => $result['manifest_sha256'],
                ]
            );
        } catch (Throwable $loggingException) {
            report($loggingException);
        }

        unset($this->health);
    } catch (Throwable $exception) {
        report($exception);
        $this->systemBackupError = $exception instanceof RuntimeException && str_contains($exception->getMessage(), 'PHP zip extension')
            ? 'Full backup unavailable because the PHP zip extension is not enabled. Enable extension=zip in the active php.ini and restart Apache.'
            : 'Full backup failed. Check the database path, private storage permissions, free disk space, PHP zip extension, and Laravel logs.';
    }
};

$health = computed(function (): array {
    $database = ['status' => 'error', 'message' => 'Database connection failed.'];
    try {
        DB::connection()->select('select 1');
        $database = ['status' => 'ok', 'message' => 'Database connection is responding.'];
    } catch (\Throwable $exception) {
        report($exception);
    }

    $syncTableExists = false;
    $serverQueueSize = 0;
    $syncErrors = 0;
    $lastSyncAt = null;
    if ($database['status'] === 'ok') {
        try {
            $syncTableExists = Schema::hasTable('offline_sync_mutations');
            if ($syncTableExists) {
                $serverQueueSize = DB::table('offline_sync_mutations')->whereNull('processed_at')->count();
                $lastSyncAt = DB::table('offline_sync_mutations')->whereNotNull('processed_at')->max('processed_at');
                $recentResults = DB::table('offline_sync_mutations')->whereNotNull('result')->orderByDesc('created_at')->limit(500)->pluck('result');
                foreach ($recentResults as $result) {
                    $decoded = is_array($result) ? $result : json_decode((string) $result, true);
                    if (is_array($decoded) && in_array($decoded['status'] ?? '', ['conflict', 'rejected'], true)) {
                        $syncErrors++;
                    }
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    $appStorageBytes = 0;
    try {
        $appStoragePath = storage_path('app');
        if (File::isDirectory($appStoragePath)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appStoragePath, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $appStorageBytes += $file->getSize();
                }
            }
        }
    } catch (\Throwable $exception) {
        report($exception);
    }

    $freeBytes = null;
    try {
        $diskFree = disk_free_space(base_path());
        $freeBytes = $diskFree === false ? null : $diskFree;
    } catch (\Throwable $exception) {
        report($exception);
    }

    $backupCandidates = [];
    foreach ([storage_path('app/backups'), storage_path('app/private/backups'), base_path('backups')] as $backupPath) {
        try {
            if (! File::isDirectory($backupPath)) {
                continue;
            }
            foreach (File::allFiles($backupPath) as $file) {
                $backupCandidates[] = ['name' => $file->getFilename(), 'path' => $file->getPathname(), 'modified' => $file->getMTime(), 'size' => $file->getSize()];
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
    usort($backupCandidates, fn (array $a, array $b) => $b['modified'] <=> $a['modified']);
    $latestBackup = $backupCandidates[0] ?? null;

    // Only list approved SQLite snapshots and full system archives from the configured backup directory.
    // Names are validated again by the download controller before any file is served.
    $downloadableBackups = [];
    $configuredBackupDirectory = storage_path('app/backups');
    try {
        if (File::isDirectory($configuredBackupDirectory)) {
            foreach (File::files($configuredBackupDirectory) as $file) {
                $filename = $file->getFilename();
                if (! $file->isFile() || ! preg_match('/\Atesda-connect-(?:db-[A-Za-z0-9_-]+\.sqlite|system-[A-Za-z0-9_-]+\.zip)\z/D', $filename)) {
                    continue;
                }

                $downloadableBackups[] = [
                    'name' => $filename,
                    'modified' => $file->getMTime(),
                    'size' => $file->getSize(),
                ];
            }
        }
    } catch (\Throwable $exception) {
        report($exception);
    }
    usort($downloadableBackups, fn (array $a, array $b) => $b['modified'] <=> $a['modified']);

    $formatBytes = static function (int|float|null $bytes): string {
        if ($bytes === null) return 'Unavailable';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
        return number_format($value, 1).' '.$units[$unit];
    };

    return [
        'database' => $database,
        'sync_table_exists' => $syncTableExists,
        'server_queue_size' => $serverQueueSize,
        'sync_errors' => $syncErrors,
        'last_sync_at' => $lastSyncAt,
        'app_storage' => $formatBytes($appStorageBytes),
        'free_space' => $formatBytes($freeBytes),
        'latest_backup' => $latestBackup,
        'backup_size' => $latestBackup ? $formatBytes($latestBackup['size']) : null,
        'backup_files' => array_map(static fn (array $backup): array => $backup + ['size_label' => $formatBytes($backup['size'])], $downloadableBackups),
    ];
});
?>

<div class="mx-auto max-w-7xl space-y-6">
    <div><p class="text-sm font-semibold text-cyan-700">System monitoring</p><flux:heading size="xl" class="mt-1 text-3xl font-black">System Health</flux:heading><flux:text class="mt-1">Live checks for database availability, offline-sync status, backup visibility, and local storage usage.</flux:text></div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <flux:card class="p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div><flux:text>Database</flux:text><flux:heading size="lg" class="mt-2">{{ $this->health['database']['status'] === 'ok' ? 'Connected' : 'Unavailable' }}</flux:heading></div><span class="grid size-10 place-items-center rounded-xl {{ $this->health['database']['status'] === 'ok' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}"><flux:icon :icon="$this->health['database']['status'] === 'ok' ? 'check-circle' : 'exclamation-triangle'" class="size-5" /></span></div><flux:text class="mt-2 text-xs">{{ $this->health['database']['message'] }}</flux:text></flux:card>
        <flux:card class="p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div><flux:text>Offline sync status</flux:text><flux:heading size="lg" class="mt-2">{{ $this->health['sync_table_exists'] ? 'Tracking active' : 'Not installed' }}</flux:heading></div><span class="grid size-10 place-items-center rounded-xl {{ $this->health['sync_table_exists'] ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700' }}"><flux:icon icon="arrow-path" class="size-5" /></span></div><flux:text class="mt-2 text-xs">{{ $this->health['sync_table_exists'] ? 'Server-side mutation tracking is available' : 'Offline-sync tracking table not installed' }}</flux:text></flux:card>
        <flux:card class="p-5 shadow-sm"><flux:text>Server sync queue</flux:text><flux:heading size="xl" class="mt-2">{{ $this->health['server_queue_size'] }}</flux:heading><flux:text class="mt-2 text-xs">Unprocessed server mutation records</flux:text></flux:card>
        <flux:card class="p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div><flux:text>Recent sync conflicts / rejects</flux:text><flux:heading size="xl" class="mt-2 {{ $this->health['sync_errors'] ? 'text-rose-700' : 'text-emerald-700' }}">{{ $this->health['sync_errors'] }}</flux:heading></div><span class="grid size-10 place-items-center rounded-xl {{ $this->health['sync_errors'] ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700' }}"><flux:icon icon="exclamation-circle" class="size-5" /></span></div><flux:text class="mt-2 text-xs">Conflicts/rejections among the latest 500 server sync records</flux:text></flux:card>
        <flux:card class="p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div><flux:text>Last recorded sync</flux:text><flux:heading size="lg" class="mt-2">{{ $this->health['last_sync_at'] ? \Illuminate\Support\Carbon::parse($this->health['last_sync_at'])->format('M d, Y') : 'No data' }}</flux:heading></div><span class="grid size-10 place-items-center rounded-xl bg-slate-100 text-slate-700"><flux:icon icon="clock" class="size-5" /></span></div><flux:text class="mt-2 text-xs">{{ $this->health['last_sync_at'] ? \Illuminate\Support\Carbon::parse($this->health['last_sync_at'])->format('h:i A') : 'No processed mutation recorded' }}</flux:text></flux:card>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card class="p-5 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3"><div><flux:heading size="lg">Backup status</flux:heading><flux:text class="mt-1">Create a verified SQLite snapshot or a full archive containing the database and private local files in <code>storage/app/backups</code>.</flux:text></div><flux:button variant="primary" icon="arrow-path" wire:click="createBackup" wire:loading.attr="disabled" wire:target="createBackup"><span wire:loading.remove wire:target="createBackup">Create backup</span><span wire:loading wire:target="createBackup">Creating backup…</span></flux:button>
                <flux:button variant="primary" icon="archive-box" wire:click="createSystemBackup" wire:loading.attr="disabled" wire:target="createSystemBackup"><span wire:loading.remove wire:target="createSystemBackup">Create full backup</span><span wire:loading wire:target="createSystemBackup">Creating full backup…</span></flux:button></div>
            @if ($backupResult)
                <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4" role="status" aria-live="polite"><div class="flex items-center gap-2 font-semibold text-emerald-800"><flux:icon icon="check-circle" class="size-5" /> Backup created and verified</div><p class="mt-2 break-all text-sm text-slate-800">{{ $backupResult['name'] }}</p><p class="mt-1 text-sm text-slate-600">{{ $backupResult['created_at'] }} · {{ number_format($backupResult['size'] / 1048576, 2) }} MB · SQLite integrity check: {{ strtoupper($backupResult['integrity']) }}</p><p class="mt-2 break-all text-xs text-emerald-900">{{ $backupResult['path'] }}</p></div>
            @endif
            @if ($backupError)
                <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">{{ $backupError }}</div>
            @endif
            @if ($systemBackupResult)
                <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4" role="status" aria-live="polite">
                    <div class="flex items-center gap-2 font-semibold text-emerald-800"><flux:icon icon="check-circle" class="size-5" /> Full system backup created and verified</div>
                    <p class="mt-2 break-all text-sm text-slate-800">{{ $systemBackupResult['name'] }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $systemBackupResult['created_at'] }} · {{ number_format($systemBackupResult['size_bytes'] / 1048576, 2) }} MB · Database integrity: {{ strtoupper($systemBackupResult['database_integrity']) }} · Private files: {{ $systemBackupResult['private_file_count'] }}</p>
                    <p class="mt-2 break-all text-xs text-emerald-900">Manifest SHA-256: {{ $systemBackupResult['manifest_sha256'] }}</p>
                    <a class="mt-2 inline-flex items-center gap-2 text-sm font-semibold text-emerald-900 underline" href="{{ route('system.backups.download', ['filename' => $systemBackupResult['name']]) }}"><flux:icon icon="arrow-down-tray" class="size-4" /> Download full backup</a>
                </div>
            @endif
            @if ($systemBackupError)
                <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">{{ $systemBackupError }}</div>
            @endif
            @if ($this->health['latest_backup'])
                <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4"><div class="flex items-center gap-2 font-semibold text-emerald-800"><flux:icon icon="check-circle" class="size-5" /> Backup file found</div><p class="mt-2 break-all text-sm text-slate-800">{{ $this->health['latest_backup']['name'] }}</p><p class="mt-1 text-sm text-slate-600">{{ \Illuminate\Support\Carbon::createFromTimestamp($this->health['latest_backup']['modified'])->format('M d, Y h:i A') }} · {{ $this->health['backup_size'] }}</p></div>
            @else
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4"><div class="flex items-center gap-2 font-semibold text-amber-800"><flux:icon icon="exclamation-triangle" class="size-5" /> No local backup found</div><p class="mt-2 text-sm text-amber-900">No files were found in the configured local backup folders. Confirm your backup destination and backup process.</p></div>
            @endif

            <div class="mt-6 border-t border-slate-200 pt-5">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <flux:heading size="base">Available backups</flux:heading>
                        <flux:text class="mt-1 text-sm">Download a dated SQLite snapshot (.sqlite) or full system archive (.zip) saved on this server.</flux:text>
                    </div>
                    <span class="text-xs text-slate-500">{{ count($this->health['backup_files']) }} file(s)</span>
                </div>

                @if (count($this->health['backup_files']))
                    <div class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200">
                        @foreach ($this->health['backup_files'] as $backup)
                            <div class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between" wire:key="backup-file-{{ md5($backup['name']) }}">
                                <div class="min-w-0">
                                    <p class="break-all text-sm font-semibold text-slate-800">{{ $backup['name'] }}</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ \Illuminate\Support\Carbon::createFromTimestamp(
    $backup['modified'],
    config('app.timezone')
) }} · {{ $backup['size_label'] }}</p>
                                </div>
                                <a href="{{ route('system.backups.download', ['filename' => $backup['name']]) }}" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-[#173b78] hover:bg-blue-50 hover:text-[#173b78] focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" aria-label="Download backup from {{ \Illuminate\Support\Carbon::createFromTimestamp($backup['modified'])->format('M d, Y h:i A') }}">
                                    <flux:icon icon="arrow-down-tray" class="size-4" />
                                    Download
                                </a>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="mt-3 rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">No downloadable database or full system backups found in <code>storage/app/backups</code>.</div>
                @endif
            </div>
        </flux:card>

        <flux:card class="p-5 shadow-sm sm:p-6">
            <flux:heading size="lg">Storage usage</flux:heading><flux:text class="mt-1">Storage/app size and free space on the server volume visible to PHP.</flux:text>
            <div class="mt-5 grid gap-4 sm:grid-cols-2"><div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-500">Application storage</p><p class="mt-2 text-2xl font-black text-slate-900">{{ $this->health['app_storage'] }}</p></div><div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-500">Free server space</p><p class="mt-2 text-2xl font-black text-slate-900">{{ $this->health['free_space'] }}</p></div></div>
            <div class="mt-5 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" x-data="{queueSize: 'Checking…', init() { try { const req = indexedDB.open('tesda-connect-offline-queue', 1); req.onupgradeneeded = () => { const db = req.result; if (!db.objectStoreNames.contains('competency-mutations')) db.createObjectStore('competency-mutations', {keyPath: 'mutationId'}); }; req.onsuccess = () => { const db = req.result; if (!db.objectStoreNames.contains('competency-mutations')) { this.queueSize = '0'; db.close(); return; } const tx = db.transaction('competency-mutations', 'readonly'); const countReq = tx.objectStore('competency-mutations').count(); countReq.onsuccess = () => { this.queueSize = String(countReq.result); db.close(); }; countReq.onerror = () => { this.queueSize = 'Unavailable'; db.close(); }; }; req.onerror = () => { this.queueSize = 'Unavailable'; }; } catch (e) { this.queueSize = 'Unavailable'; } } }"><p class="font-semibold">This browser’s local offline queue</p><p class="mt-1"><span class="font-bold" x-text="queueSize"></span> queued competency changes</p><p class="mt-1 text-xs text-blue-800">This number is local to the browser you are using; it does not represent every user's device.</p></div>
        </flux:card>
    </div>

    <flux:card class="p-5 shadow-sm sm:p-6"><div class="flex flex-wrap items-start justify-between gap-3"><div><flux:heading size="lg">Health notes</flux:heading><flux:text class="mt-1">Interpretation of the checks above.</flux:text></div><flux:button variant="ghost" icon="arrow-path" wire:click="$refresh">Refresh checks</flux:button></div><ul class="mt-4 list-disc space-y-2 pl-5 text-sm text-slate-600"><li>A successful database check confirms the current PHP process can execute a simple query; it is not a full performance test.</li><li>Server sync records reflect mutations accepted by the server. Pending local records remain on each user's browser until synchronized.</li><li>SQLite backup uses VACUUM INTO and PRAGMA integrity_check. Full system backup also includes private local files, a SHA-256 manifest, and archive checksum validation; neither check replaces a restore test.</li><li>Keep a second copy of full backups off this computer. A backup stored only on the same server may be lost with the server.</li><li>Storage usage covers <code>storage/app</code>; free space is reported for the volume containing the application path.</li></ul></flux:card>
</div>
