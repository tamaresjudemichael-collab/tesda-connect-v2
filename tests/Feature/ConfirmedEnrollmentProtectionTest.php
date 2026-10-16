<?php

use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\User;
use Livewire\Livewire;

test('confirmed scholars cannot resubmit registration and change enrollment details', function () {
    $institution = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);

    $scholar = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $scholar->institutions()->attach($institution->id, ['is_primary' => true]);

    $application = EnrollmentApplication::create([
        'reference_no' => 'TC-'.now()->format('Y').'-LOCK0001',
        'learner_user_id' => $scholar->id,
        'institution_id' => $institution->id,
        'learner_name' => $scholar->name,
        'email' => $scholar->email,
        'application_status' => 'confirmed',
        'record_completeness' => 100,
        'submitted_at' => now(),
        'verified_at' => now(),
        'confirmed_at' => now(),
        'scholar_id' => 'BST-'.now()->format('Y').'-0001',
    ]);

    $this->actingAs($scholar);

    Livewire::test('learner.registration')
        ->call('save')
        ->assertStatus(200);

    expect($application->fresh()->application_status)->toBe('confirmed')
        ->and($application->fresh()->scholar_id)->toBe('BST-'.now()->format('Y').'-0001');
});
