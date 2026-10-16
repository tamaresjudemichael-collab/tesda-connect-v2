<?php

use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\EnrollmentStatusHistory;
use App\Models\Institution;
use App\Models\LearnerDocument;
use App\Models\LearnerProfile;
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

    $this->course = Course::create([
        'institution_id' => $this->institution->id,
        'code' => 'OAP-NCII',
        'name' => 'Organic Agriculture Production NC II',
        'training_hours' => 232,
        'is_active' => true,
    ]);

    $this->applicant = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $this->profile = LearnerProfile::create([
        'user_id' => $this->applicant->id,
        'institution_id' => $this->institution->id,
        'course_id' => $this->course->id,
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'city_municipality' => 'Mamburao',
        'province' => 'Occidental Mindoro',
        'region' => 'MIMAROPA',
        'contact_number' => '09171234567',
        'nationality' => 'Filipino',
        'sex' => 'male',
        'civil_status' => 'Single',
        'birthdate' => '2000-01-01',
        'educational_attainment' => 'No Grade Completed',
        'privacy_consent' => true,
        'submitted_at' => now(),
    ]);

    $this->application = EnrollmentApplication::create([
        'reference_no' => 'TC-'.now()->format('Y').'-VERIFY01',
        'learner_user_id' => $this->applicant->id,
        'institution_id' => $this->institution->id,
        'course_id' => $this->course->id,
        'learner_name' => 'Juan Dela Cruz',
        'email' => $this->applicant->email,
        'application_status' => 'submitted',
        'record_completeness' => 70,
        'submitted_at' => now(),
    ]);
});

function createLearnerDocumentForVerification(int $userId, int $profileId, string $type, string $status = 'verified'): LearnerDocument
{
    return LearnerDocument::create([
        'user_id' => $userId,
        'learner_profile_id' => $profileId,
        'document_type' => $type,
        'original_name' => $type.'.pdf',
        'path' => 'learner-documents/'.$userId.'/'.$type.'.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1234,
        'status' => $status,
    ]);
}

test('registrar cannot verify an application until the birth certificate is approved', function () {
    createLearnerDocumentForVerification(
        $this->applicant->id,
        $this->profile->id,
        'birth_certificate',
        'pending'
    );

    $this->actingAs($this->registrar);

    Livewire::test('enrollments.index')
        ->call('verify', $this->application->id)
        ->assertHasNoErrors();

    $this->application->refresh();

    expect($this->application->application_status)->toBe('submitted')
        ->and($this->application->verified_at)->toBeNull()
        ->and($this->application->record_completeness)->toBeLessThan(100);
});

test('registrar can verify an application when profile and required documents are complete', function () {
    createLearnerDocumentForVerification(
        $this->applicant->id,
        $this->profile->id,
        'birth_certificate'
    );

    $this->actingAs($this->registrar);

    Livewire::test('enrollments.index')
        ->call('verify', $this->application->id)
        ->assertHasNoErrors();

    $this->application->refresh();

    expect($this->application->application_status)->toBe('verified')
        ->and($this->application->verified_at)->not->toBeNull()
        ->and($this->application->record_completeness)->toBe(100)
        ->and(EnrollmentStatusHistory::query()
            ->where('reference_no', $this->application->reference_no)
            ->where('from_status', 'submitted')
            ->where('to_status', 'verified')
            ->exists())->toBeTrue();
});

test('married learners require an approved marriage contract before verification', function () {
    $this->profile->update(['civil_status' => 'Married']);
    createLearnerDocumentForVerification($this->applicant->id, $this->profile->id, 'birth_certificate');

    $this->actingAs($this->registrar);

    Livewire::test('enrollments.index')
        ->call('verify', $this->application->id)
        ->assertHasNoErrors();

    createLearnerDocumentForVerification($this->applicant->id, $this->profile->id, 'marriage_contract');

    Livewire::test('enrollments.index')
        ->call('verify', $this->application->id)
        ->assertHasNoErrors();

    expect($this->application->fresh()->application_status)->toBe('verified');
});

test('high school and college graduates require an approved TOR before verification', function () {
    $this->profile->update(['educational_attainment' => 'College Graduate']);
    createLearnerDocumentForVerification($this->applicant->id, $this->profile->id, 'birth_certificate');

    $this->actingAs($this->registrar);

    Livewire::test('enrollments.index')
        ->call('verify', $this->application->id)
        ->assertHasNoErrors();

    createLearnerDocumentForVerification($this->applicant->id, $this->profile->id, 'tor');

    Livewire::test('enrollments.index')
        ->call('verify', $this->application->id)
        ->assertHasNoErrors();

    expect($this->application->fresh()->application_status)->toBe('verified');
});
