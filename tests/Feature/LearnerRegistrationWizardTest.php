<?php

use App\Models\Course;
use App\Models\Institution;
use App\Models\User;
use Livewire\Livewire;

test('learner registration wizard validates each step and preserves prior values', function () {
    $institution = Institution::create([
        'code' => 'WIZ',
        'name' => 'Wizard Test Institution',
        'is_active' => true,
    ]);

    $course = Course::create([
        'institution_id' => $institution->id,
        'code' => 'WIZ-COURSE',
        'name' => 'Wizard Test Course',
        'training_hours' => 120,
        'is_active' => true,
    ]);

    $learner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $this->actingAs($learner);

    Livewire::test('learner.registration')
        ->call('nextStep')
        ->assertHasErrors(['course_id'])
        ->assertSet('current_step', 1)
        ->set('institution_id', $institution->id)
        ->set('course_id', $course->id)
        ->call('nextStep')
        ->assertHasNoErrors()
        ->assertSet('current_step', 2)
        ->set('first_name', 'Juan')
        ->call('previousStep')
        ->assertSet('current_step', 1)
        ->assertSet('institution_id', $institution->id)
        ->assertSet('course_id', $course->id);
});
