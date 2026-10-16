<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use App\Http\Controllers\LearnerDocumentController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\TrainingCertificateController;
use App\Http\Controllers\EnrollmentBatchForwardingController;
use App\Http\Controllers\AnnouncementAttachmentController;
use App\Http\Controllers\CourseMaterialDownloadController;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'active'])->group(function () {
    Volt::route('dashboard', 'dashboard')->name('dashboard');
    Volt::route('user-guide', 'user-guide')->name('user-guide');

    Volt::route('enrollments', 'enrollments.index')
        ->middleware('role:administrator,registrar')
        ->name('enrollments.index');

    Volt::route('schedules', 'schedules.index')
        ->middleware('role:administrator,registrar,focal_person')
        ->name('schedules.index');

    Volt::route('progress', 'progress.index')
        ->middleware('role:administrator,focal_person')
        ->name('progress.index');

    Volt::route('competency-setup', 'competencies.index')
        ->middleware('role:administrator,focal_person')
        ->name('competencies.index');

    Volt::route('attendance', 'attendance.index')
        ->middleware('role:administrator,focal_person')
        ->name('attendance.index');

        
Volt::route('competency-progress', 'progress.competency')
    ->middleware('role:administrator,focal_person')
    ->name('progress.competency');


    Volt::route('reports', 'reports.index')
        ->middleware('role:administrator,registrar,focal_person')
        ->name('reports.index');

    Route::get('/reports/export/{type}', [ReportExportController::class, 'export'])
        ->whereIn('type', ['enrollments', 'competency-progress', 'attendance'])
        ->middleware('role:administrator,registrar,focal_person')
        ->name('reports.export');

    Volt::route('announcements', 'announcements.index')
        ->middleware('role:administrator,registrar,focal_person')
        ->name('announcements.index');

    Volt::route('learning/materials', 'learning.materials')
        ->middleware('role:administrator,focal_person')
        ->name('learning.materials');

    Volt::route('my-learning/materials', 'learning.my-materials')
        ->middleware('role:applicant,scholar')
        ->name('learning.my-materials');

    Route::get('/course-materials/{material}/download', CourseMaterialDownloadController::class)
        ->name('course-materials.download');

    Volt::route('certification', 'certification.index')
        ->middleware('role:administrator,focal_person')
        ->name('certification.index');

    Route::get('/certificates/{certificate}/print', [TrainingCertificateController::class, 'print'])
        ->name('certificates.print');

    Volt::route('system/accounts', 'system.accounts')
        ->middleware('role:administrator')
        ->name('system.accounts');

        Volt::route('system/institutions', 'system.institutions')
    ->middleware('role:administrator')
    ->name('system.institutions');

    Volt::route('system/batches', 'system.batches')
    ->middleware('role:administrator')
    ->name('system.batches');

    Volt::route('system/courses', 'system.courses')
    ->middleware('role:administrator')
    ->name('system.courses');

    Volt::route('system/activity', 'system.activity')
        ->middleware('role:administrator')
        ->name('system.activity');

    Volt::route('registrar/account-requests', 'registrar.account-requests')->middleware('role:registrar')->name('registrar.account-requests');
    Volt::route('registrar/intake', 'registrar.intake')->middleware('role:registrar')->name('registrar.intake');
    Volt::route('registrar/forward-batches', 'registrar.forward-batches')->middleware('role:registrar')->name('registrar.forward-batches');
    Volt::route('registrar/student-records', 'registrar.student-records')->middleware('role:registrar')->name('registrar.student-records');
    Volt::route('registrar/accounts', 'registrar.accounts')->middleware('role:registrar')->name('registrar.accounts');
    Volt::route('registrar/institutions', 'registrar.institutions')->middleware('role:registrar')->name('registrar.institutions');

    Volt::route('system/forwarded-batches', 'system.forwarded-batches')->middleware('role:administrator')->name('system.forwarded-batches');

    Route::get('/enrollment-forwardings/{forwarding}/summary', [EnrollmentBatchForwardingController::class, 'download'])
        ->middleware('role:administrator,registrar')->name('enrollment-forwardings.summary');

    Route::get('/announcements/{announcement}/attachment', [AnnouncementAttachmentController::class, 'download'])
        ->middleware('role:administrator,registrar,focal_person,applicant,scholar')->name('announcements.attachment');

Volt::route('my-learning', 'learner.portal')->middleware('role:applicant,scholar')->name('learner.portal');

    Volt::route('registration', 'learner.registration')
        ->middleware('role:applicant,scholar')
        ->name('learner.registration');

        Route::get(
    '/learner-documents/{document}/download',
    [LearnerDocumentController::class, 'download']
)->name('learner-documents.download');
});

require __DIR__.'/admin-oversight.php';
require __DIR__.'/offline-sync.php';
require __DIR__.'/student.php';

require __DIR__.'/settings.php';
require __DIR__.'/focal-person.php';

// Student / Scholar requirements route
require __DIR__.'/student.php';
