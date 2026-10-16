<?php

use App\Http\Controllers\FocalAdminTaskController;
use App\Http\Controllers\OperationalDocumentController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['auth', 'active', 'role:focal_person'])->group(function (): void {
    Volt::route('focal/courses', 'focal.courses')->name('focal.courses');
    Volt::route('focal/documents', 'focal.documents')->name('focal.documents');
    Route::post('focal/documents/offline-upload', [OperationalDocumentController::class, 'storeFromOfflineQueue'])->name('focal.documents.offline-upload');
    Volt::route('focal/forms', 'focal.forms')->name('focal.forms');

    Route::get('focal/forms/{form}/download', [FocalAdminTaskController::class, 'download'])
        ->name('focal.forms.download');
    Route::get('focal/forms/{form}/print', [FocalAdminTaskController::class, 'print'])
        ->name('focal.forms.print');
});

Route::get('operational-documents/{document}/download', [OperationalDocumentController::class, 'download'])
    ->middleware(['auth', 'active', 'role:focal_person'])
    ->name('focal.documents.download');
