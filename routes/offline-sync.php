<?php

use App\Http\Controllers\OfflineCompetencyProgressSyncController;
use Illuminate\Support\Facades\Route;

Route::post('/offline-sync/competency-progress', [OfflineCompetencyProgressSyncController::class, 'store'])
    ->middleware(['auth', 'active', 'role:administrator,focal_person'])
    ->name('offline-sync.competency-progress');
