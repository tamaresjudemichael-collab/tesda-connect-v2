<?php

use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\User;

beforeEach(function () {
    $this->administrator = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $this->institution = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);

    foreach ([
        ['TC-2026-SIDEBAR-SUB', 'Submitted learner', 'submitted'],
        ['TC-2026-SIDEBAR-VER', 'Verified learner', 'verified'],
        ['TC-2026-SIDEBAR-CON', 'Confirmed learner', 'confirmed'],
    ] as [$reference, $learnerName, $status]) {
        EnrollmentApplication::create([
            'reference_no' => $reference,
            'institution_id' => $this->institution->id,
            'learner_name' => $learnerName,
            'email' => strtolower(str_replace(' ', '.', $learnerName)).'@example.test',
            'application_status' => $status,
            'record_completeness' => 100,
            'submitted_at' => now(),
            'verified_at' => in_array($status, ['verified', 'confirmed'], true) ? now() : null,
            'confirmed_at' => $status === 'confirmed' ? now() : null,
        ]);
    }
});

test('submissions sidebar destination prefilters to submitted applications', function () {
    $this->actingAs($this->administrator)
        ->get(route('enrollments.index', ['status' => 'submitted']))
        ->assertOk()
        ->assertSee('Submitted learner')
        ->assertDontSee('Verified learner')
        ->assertDontSee('Confirmed learner');
});

test('verified records sidebar destination includes verified and confirmed applications only', function () {
    $this->actingAs($this->administrator)
        ->get(route('enrollments.index', ['status' => 'verified_records']))
        ->assertOk()
        ->assertSee('Verified learner')
        ->assertSee('Confirmed learner')
        ->assertDontSee('Submitted learner');
});
