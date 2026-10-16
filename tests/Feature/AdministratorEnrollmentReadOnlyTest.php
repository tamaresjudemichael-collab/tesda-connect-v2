<?php

use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\User;
use Livewire\Livewire;

function createReadOnlyTestApplication(Institution $institution, User $applicant): EnrollmentApplication
{
    return EnrollmentApplication::create([
        'reference_no' => 'TC-'.now()->format('Y').'-'.strtoupper(\Illuminate\Support\Str::random(8)),
        'learner_user_id' => $applicant->id,
        'institution_id' => $institution->id,
        'learner_name' => $applicant->name,
        'email' => $applicant->email,
        'application_status' => 'submitted',
        'record_completeness' => 0,
        'submitted_at' => now(),
    ]);
}

function createReadOnlyTestInstitution(): Institution
{
    return Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);
}

test('administrator can view submitted student registration across institutions without action controls', function () {
    $administrator = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);
    $applicant = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
        'name' => 'Student Sample',
    ]);
    $application = createReadOnlyTestApplication(createReadOnlyTestInstitution(), $applicant);

    $response = $this->actingAs($administrator)
        ->get(route('enrollments.index', ['status' => 'submitted']))
        ->assertOk()
        ->assertSee('Student Enrollment Submissions')
        ->assertSee('Read only')
        ->assertSee($application->learner_name)
        ->assertSee($application->reference_no)
        ->assertDontSee('New learner record')
        ->assertDontSee('Confirm enrollment')
        ->assertDontSee('wire:click="verify('.$application->id.')', false)
        ->assertDontSee('wire:click="returnForRevision('.$application->id.')', false);
});

test('administrator cannot execute enrollment or document changes through Livewire actions', function () {
    $administrator = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);
    $applicant = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);
    $application = createReadOnlyTestApplication(createReadOnlyTestInstitution(), $applicant);

    $this->actingAs($administrator);

    foreach ([
        ['openCreate', []],
        ['save', []],
        ['edit', [$application->id]],
        ['verify', [$application->id]],
        ['confirm', [$application->id]],
        ['returnForRevision', [$application->id]],
        ['delete', [$application->id]],
        ['verifyDocument', [999999]],
        ['rejectDocument', [999999]],
    ] as [$action, $arguments]) {
        Livewire::test('enrollments.index')
            ->call($action, ...$arguments)
            ->assertStatus(403);
    }

    expect($application->fresh()->application_status)->toBe('submitted')
        ->and($application->fresh()->scholar_id)->toBeNull();
});

test('registrar can see a submitted student registration for an assigned institution', function () {
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);
    $institution = createReadOnlyTestInstitution();
    $registrar->institutions()->attach($institution->id, ['is_primary' => true]);
    $applicant = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
        'name' => 'Registrar Queue Sample',
    ]);
    $application = createReadOnlyTestApplication($institution, $applicant);

    $this->actingAs($registrar)
        ->get(route('enrollments.index', ['status' => 'submitted']))
        ->assertOk()
        ->assertSee($application->learner_name)
        ->assertSee('Verify')
        ->assertSee('Return');
});
