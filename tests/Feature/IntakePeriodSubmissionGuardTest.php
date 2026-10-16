<?php

use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\IntakePeriod;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

test('new learner submission is blocked and intake auto-locks when quota is full', function () {
    $institution = Institution::create(['code' => 'BST', 'name' => 'BST Training Center', 'is_active' => true]);
    $course = Course::create(['institution_id' => $institution->id, 'code' => 'BPP-NC-II', 'name' => 'Bread and Pastry Production NC II', 'training_hours' => 141, 'is_active' => true]);
    $period = IntakePeriod::create([
        'code' => 'QUOTA-FULL',
        'name' => 'Quota Full Intake',
        'starts_at' => today()->subDay()->toDateString(),
        'ends_at' => today()->addDays(30)->toDateString(),
        'quota' => 1,
    ]);
    EnrollmentApplication::create([
        'reference_no' => 'TC-'.now()->format('Y').'-QUOTAFUL1',
        'institution_id' => $institution->id,
        'course_id' => $course->id,
        'learner_name' => 'Existing Trainee',
        'application_status' => 'submitted',
        'submitted_at' => now(),
    ]);

    $applicant = User::factory()->create(['role' => 'applicant', 'account_status' => 'active']);
    $this->actingAs($applicant);

    Livewire::test('learner.registration')
        ->set('institution_id', $institution->id)
        ->set('course_id', $course->id)
        ->set('last_name', 'New')
        ->set('first_name', 'Applicant')
        ->set('city_municipality', 'General Trias')
        ->set('province', 'Cavite')
        ->set('region', 'Region IV-A')
        ->set('contact_number', '09171234567')
        ->set('nationality', 'Filipino')
        ->set('sex', 'male')
        ->set('civil_status', 'Single')
        ->set('birthdate', today()->subYears(20)->toDateString())
        ->set('educational_attainment', 'High School Graduate')
        ->set('privacy_consent', true)
        ->set('birth_certificate', UploadedFile::fake()->create('birth-certificate.pdf', 20, 'application/pdf'))
        ->set('tor', UploadedFile::fake()->create('tor.pdf', 20, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('enrollment_applications', ['learner_user_id' => $applicant->id]);
    $this->assertDatabaseHas('intake_periods', ['id' => $period->id, 'is_locked' => 1]);
});
