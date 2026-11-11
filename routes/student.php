<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['auth', 'active', 'role:applicant,scholar'])->group(function (): void {
    Volt::route('learner/requirements', 'learner.requirements')
        ->name('learner.requirements');

    Volt::route('learner/learning-progress', 'learner.learning-progress')
        ->name('learner.learning-progress');

    Volt::route('learner/training-competency-progress', 'learner.competency-progress')
        ->name('learner.competency-progress');

    Volt::route('learner/attendance', 'learner.attendance')
        ->name('learner.attendance');

    Volt::route('learner/certification-completion', 'learner.certification')
        ->name('learner.certification');

    Volt::route('learner/documents', 'learner.documents')
        ->name('learner.documents');

    Volt::route('learner/announcements', 'learner.announcements')
        ->name('learner.announcements');

});
