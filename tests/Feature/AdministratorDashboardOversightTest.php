<?php

use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Models\TrainingCertificate;
use App\Models\User;
use Livewire\Livewire;

function createAdminDashboardInstitution(string $code): Institution
{
    return Institution::create([
        'code' => $code,
        'name' => $code === 'BST' ? 'Balli School of Technology' : 'Mamburao Integrated Farm',
        'is_active' => true,
    ]);
}

function createAdminDashboardApplication(Institution $institution, string $ref, string $status, ?string $submittedAt = null): EnrollmentApplication
{
    return EnrollmentApplication::create([
        'reference_no' => $ref,
        'institution_id' => $institution->id,
        'learner_name' => 'Test Learner '.$ref,
        'application_status' => $status,
        'record_completeness' => 100,
        'submitted_at' => $submittedAt ?? now(),
    ]);
}

test('administrator dashboard shows global quick stats and BST versus MIF comparison', function () {
    $administrator = User::factory()->create(['role' => 'administrator']);
    $bst = createAdminDashboardInstitution('BST');
    $mif = createAdminDashboardInstitution('MIF');

    $confirmedBst = createAdminDashboardApplication($bst, 'DASH-BST-001', 'confirmed');
    createAdminDashboardApplication($bst, 'DASH-BST-002', 'submitted');
    createAdminDashboardApplication($mif, 'DASH-MIF-001', 'under_review');
    TrainingCertificate::create([
        'enrollment_application_id' => $confirmedBst->id,
        'status' => 'completed',
    ]);

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('System overview')
        ->assertSee('Active users')
        ->assertSee('Confirmed enrollees')
        ->assertSee('Pending tasks')
        ->assertSee('Pending verifications')
        ->assertSee('Schedule deadlines')
        ->assertSee('BST vs MIF comparison')
        ->assertSee('Balli School of Technology')
        ->assertSee('Mamburao Integrated Farm')
        ->assertSee('Completion rate = completed certificates ÷ confirmed enrollees.');
});

test('administrator dashboard counts monthly intake and upcoming batch deadlines', function () {
    $administrator = User::factory()->create(['role' => 'administrator']);
    $bst = createAdminDashboardInstitution('BST');
    $course = \App\Models\Course::create([
        'institution_id' => $bst->id,
        'code' => 'DASH-COURSE',
        'name' => 'Dashboard Test Course',
        'training_hours' => 100,
        'is_active' => true,
    ]);

    createAdminDashboardApplication($bst, 'DASH-CURRENT-001', 'submitted', now()->toDateTimeString());
    createAdminDashboardApplication($bst, 'DASH-OLD-001', 'confirmed', now()->subMonths(2)->toDateTimeString());
    TrainingBatch::create([
        'institution_id' => $bst->id,
        'course_id' => $course->id,
        'code' => 'DASH-DEADLINE-001',
        'name' => 'Ending Soon',
        'capacity' => 20,
        'starts_at' => today()->subDays(5)->toDateString(),
        'ends_at' => today()->addDays(10)->toDateString(),
        'status' => 'in_training',
    ]);

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('This month’s intake')
        ->assertSee('Applications submitted this month')
        ->assertSee('Batch end dates within the next 30 days');
});

test('non-administrator dashboard does not show global BST and MIF oversight', function () {
    $registrar = User::factory()->create(['role' => 'registrar']);

    $this->actingAs($registrar)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('System overview')
        ->assertDontSee('BST vs MIF comparison');
});
