<?php

use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\IntakePeriod;
use App\Models\User;
use Livewire\Livewire;

function intakeFeatureFixture(): array
{
    $institution = Institution::create([
        'code' => 'BST',
        'name' => 'BST Training Center',
        'is_active' => true,
    ]);
    $course = Course::create([
        'institution_id' => $institution->id,
        'code' => 'BPP-NC-II',
        'name' => 'Bread and Pastry Production NC II',
        'training_hours' => 141,
        'is_active' => true,
    ]);

    return [$institution, $course];
}

test('administrator can view and create intake periods', function () {
    $admin = User::factory()->create(['role' => 'administrator', 'account_status' => 'active']);
    $this->actingAs($admin)->get(route('system.intake-summary'))->assertOk()->assertSee('Intake Summary');

    Livewire::test('system.intake-summary')
        ->set('code', 'INTAKE-TEST-01')
        ->set('name', 'Test intake period')
        ->set('starts_at', today()->toDateString())
        ->set('ends_at', today()->addDays(30)->toDateString())
        ->set('quota', 2)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Test intake period');

    $this->assertDatabaseHas('intake_periods', ['code' => 'INTAKE-TEST-01', 'quota' => 2]);
});

test('intake period locks automatically when submitted applications reach quota', function () {
    $admin = User::factory()->create(['role' => 'administrator', 'account_status' => 'active']);
    [$institution, $course] = intakeFeatureFixture();
    $period = IntakePeriod::create([
        'code' => 'FULL-INTAKE', 'name' => 'Full intake',
        'starts_at' => today()->subDay()->toDateString(), 'ends_at' => today()->addDay()->toDateString(),
        'quota' => 1, 'created_by_user_id' => $admin->id,
    ]);
    EnrollmentApplication::create([
        'reference_no' => 'TC-TEST-FULL-1', 'institution_id' => $institution->id,
        'course_id' => $course->id, 'learner_name' => 'Sample Trainee',
        'application_status' => 'submitted', 'submitted_at' => now(),
    ]);

    Livewire::actingAs($admin)->test('system.intake-summary')
        ->assertSee('Full · Locked');

    $this->assertDatabaseHas('intake_periods', ['id' => $period->id, 'is_locked' => 1]);
});

test('non-administrator cannot open intake summary', function () {
    $registrar = User::factory()->create(['role' => 'registrar', 'account_status' => 'active']);
    $this->actingAs($registrar)->get(route('system.intake-summary'))->assertForbidden();
});
