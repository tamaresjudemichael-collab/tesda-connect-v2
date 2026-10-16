<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['auth', 'active', 'role:applicant,scholar'])->group(function (): void {
    Volt::route('learner/requirements', 'learner.requirements')
        ->name('learner.requirements');
});
