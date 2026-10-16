
<?php

use App\Models\EnrollmentApplication;
use App\Models\EnrollmentStatusHistory;
use App\Models\Institution;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $this->institution = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);

    $this->registrar->institutions()->attach($this->institution->id, [
        'is_primary' => true,
    ]);

    $this->applicant = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $this->application = EnrollmentApplication::create([
        'reference_no' => 'TC-'.now()->format('Y').'-TEST0001',
        'learner_user_id' => $this->applicant->id,
        'institution_id' => $this->institution->id,
        'learner_name' => $this->applicant->name,
        'email' => $this->applicant->email,
        'application_status' => 'verified',
        'record_completeness' => 100,
        'submitted_at' => now(),
        'verified_at' => now(),
    ]);
});

test('registrar can confirm a verified application and issue a scholar id', function () {
    $this->actingAs($this->registrar);

    Livewire::test('enrollments.index')
        ->call('confirm', $this->application->id)
        ->assertHasNoErrors();

    $this->application->refresh();
    $this->applicant->refresh();

    expect($this->application->application_status)->toBe('confirmed')
        ->and($this->application->confirmed_at)->not->toBeNull()
        ->and($this->application->scholar_id)->toBe('BST-'.now()->format('Y').'-0001')
        ->and($this->applicant->role)->toBe('scholar')
        ->and($this->applicant->institutions()->whereKey($this->institution->id)->exists())->toBeTrue()
        ->and(EnrollmentStatusHistory::query()
            ->where('reference_no', $this->application->reference_no)
            ->where('from_status', 'verified')
            ->where('to_status', 'confirmed')
            ->exists())->toBeTrue();
});

test('unverified application cannot be confirmed', function () {
    $this->application->update([
        'application_status' => 'submitted',
        'verified_at' => null,
    ]);

    $this->actingAs($this->registrar);

    Livewire::test('enrollments.index')
        ->call('confirm', $this->application->id)
        ->assertStatus(422);

    $this->application->refresh();

    expect($this->application->application_status)->toBe('submitted')
        ->and($this->application->confirmed_at)->toBeNull()
        ->and($this->application->scholar_id)->toBeNull();
});

test('applicant without a linked account cannot be confirmed', function () {
    $this->application->update([
        'learner_user_id' => null,
    ]);

    $this->actingAs($this->registrar);

    Livewire::test('enrollments.index')
        ->call('confirm', $this->application->id)
        ->assertStatus(422);

    $this->application->refresh();

    expect($this->application->application_status)->toBe('verified')
        ->and($this->application->confirmed_at)->toBeNull()
        ->and($this->application->scholar_id)->toBeNull();
});
