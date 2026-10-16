<?php

use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\LearnerProfile;
use App\Models\User;
use Livewire\Livewire;

test('account with linked learner records cannot be permanently deleted', function () {
    $institution = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);

    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $learner = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    LearnerProfile::create([
        'user_id' => $learner->id,
        'institution_id' => $institution->id,
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'privacy_consent' => true,
    ]);

    EnrollmentApplication::create([
        'reference_no' => 'TC-'.now()->format('Y').'-DEL00001',
        'learner_user_id' => $learner->id,
        'institution_id' => $institution->id,
        'learner_name' => 'Juan Dela Cruz',
        'email' => $learner->email,
        'application_status' => 'submitted',
        'record_completeness' => 20,
        'submitted_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test('system.accounts')
        ->call('delete', $learner->id)
        ->assertHasNoErrors();

    expect(User::query()->whereKey($learner->id)->exists())->toBeTrue()
        ->and(EnrollmentApplication::query()->where('learner_user_id', $learner->id)->exists())->toBeTrue()
        ->and(LearnerProfile::query()->where('user_id', $learner->id)->exists())->toBeTrue();
});
