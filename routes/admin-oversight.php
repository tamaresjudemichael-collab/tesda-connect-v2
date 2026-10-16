<?php

use App\Http\Controllers\Admin\DownloadSystemBackupController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['auth', 'active', 'role:administrator'])->group(function (): void {
    Volt::route('system/intake-summary', 'system.intake-summary')->name('system.intake-summary');
    Volt::route('system/calendar', 'system.calendar')->name('system.calendar');
    Volt::route('system/duration-compliance', 'system.duration-compliance')->name('system.duration-compliance');
    Volt::route('system/health', 'system.health')->name('system.health');
    Route::get('system/backups/{filename}/download', DownloadSystemBackupController::class)
        ->where('filename', 'tesda-connect-(db-[A-Za-z0-9_-]+\.sqlite|system-[A-Za-z0-9_-]+\.zip)')
        ->name('system.backups.download');
});
