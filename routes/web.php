<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('dashboard', 'dashboard')->name('dashboard');

    Volt::route('enrollments', 'enrollments.index')
        ->middleware('role:administrator,registrar')
        ->name('enrollments.index');

    Volt::route('schedules', 'schedules.index')
        ->middleware('role:administrator,registrar,focal_person')
        ->name('schedules.index');

    Volt::route('progress', 'progress.index')
        ->middleware('role:administrator,focal_person')
        ->name('progress.index');

    Volt::route('reports', 'reports.index')
        ->middleware('role:administrator,registrar,focal_person')
        ->name('reports.index');

    Volt::route('system/accounts', 'system.accounts')
        ->middleware('role:administrator')
        ->name('system.accounts');

    Volt::route('system/activity', 'system.activity')
        ->middleware('role:administrator')
        ->name('system.activity');

    Volt::route('my-learning', 'learner.portal')
        ->middleware('role:scholar')
        ->name('learner.portal');

    Volt::route('registration', 'learner.registration')
        ->middleware('role:scholar')
        ->name('learner.registration');
});

require __DIR__.'/settings.php';
