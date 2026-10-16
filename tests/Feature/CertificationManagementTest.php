<?php

use App\Models\CompetencyProgress;
use App\Models\CompetencyTask;
use App\Models\CompetencyUnit;
use App\Models\CompetencyUnitReview;
use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Models\TrainingCertificate;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

function createTrainingCertificateFixture(bool $complete = false, string $institutionCode = 'BST'): array
{
    $institution = Institution::create([
        'code' => $institutionCode,
        'name' => $institutionCode === 'BST' ? 'Balli School of Technology' : 'Mamburao Integrated Farm',
        'is_active' => true,
    ]);

    $course = Course::create([
        'institution_id' => $institution->id,
        'code' => 'OAP-'.$institutionCode,
        'name' => 'Organic Agriculture Production NC II',
        'qualification_level' => 'NC II',
        'training_hours' => 232,
        'is_active' => true,
    ]);

    $batch = TrainingBatch::create([
        'institution_id' => $institution->id,
        'course_id' => $course->id,
        'code' => 'CERT-'.$institutionCode,
        'name' => 'Organic Agriculture Certificate Batch',
        'capacity' => 25,
        'starts_at' => now()->subMonths(6)->toDateString(),
        'ends_at' => now()->subDay()->toDateString(),
        'status' => 'completed',
    ]);

    $learner = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $application = EnrollmentApplication::create([
        'reference_no' => 'CERT-'.Str::upper(Str::random(12)),
        'learner_user_id' => $learner->id,
        'institution_id' => $institution->id,
        'course_id' => $course->id,
        'training_batch_id' => $batch->id,
        'learner_name' => $learner->name,
        'email' => $learner->email,
        'application_status' => 'confirmed',
        'record_completeness' => 100,
        'confirmed_at' => now()->subMonths(6),
        'scholar_id' => $institutionCode.'-'.now()->format('Y').'-'.Str::upper(Str::random(6)),
    ]);

    $focal = User::factory()->create([
        'role' => 'focal_person',
        'account_status' => 'active',
    ]);
    $focal->institutions()->attach($institution->id, ['is_primary' => true]);

    $unit = CompetencyUnit::create([
        'course_id' => $course->id,
        'unit_number' => 1,
        'name' => 'Prepare organic production area',
        'sort_order' => 1,
    ]);

    foreach (['progress', 'achievement'] as $index => $chartType) {
        $task = CompetencyTask::create([
            'competency_unit_id' => $unit->id,
            'chart_type' => $chartType,
            'task_number' => 1,
            'name' => strtoupper($chartType).' task 1',
            'sort_order' => $index + 1,
        ]);

        if ($complete) {
            CompetencyProgress::create([
                'enrollment_application_id' => $application->id,
                'competency_task_id' => $task->id,
                'status' => 'competent',
                'assessed_by' => $focal->id,
                'assessed_at' => now(),
            ]);
        }
    }

    if ($complete) {
        CompetencyUnitReview::create([
            'enrollment_application_id' => $application->id,
            'competency_unit_id' => $unit->id,
            'status' => 'verified',
            'verified_by' => $focal->id,
            'verified_at' => now(),
            'is_locked' => true,
        ]);
    }

    return compact('institution', 'course', 'batch', 'learner', 'application', 'focal', 'unit');
}

test('certificate cannot be issued before all competency tasks and unit reviews are complete', function () {
    $fixture = createTrainingCertificateFixture(false);

    $this->actingAs($fixture['focal']);

    Livewire::test('certification.index')
        ->call('issueCertificate', $fixture['application']->id)
        ->assertHasErrors('completion');

    expect(TrainingCertificate::query()->where('enrollment_application_id', $fixture['application']->id)->exists())->toBeFalse();
});

test('focal person can issue a completion certificate after all requirements are verified', function () {
    $fixture = createTrainingCertificateFixture(true);

    $this->actingAs($fixture['focal']);

    Livewire::test('certification.index')
        ->call('issueCertificate', $fixture['application']->id)
        ->assertHasNoErrors();

    $certificate = TrainingCertificate::query()
        ->where('enrollment_application_id', $fixture['application']->id)
        ->firstOrFail();

    expect($certificate->status)->toBe('completed')
        ->and($certificate->certificate_number)->not->toBeNull()
        ->and($certificate->issued_at)->not->toBeNull()
        ->and($certificate->issued_by_user_id)->toBe($fixture['focal']->id);

    $this->actingAs($fixture['learner'])
        ->get(route('certificates.print', $certificate))
        ->assertOk()
        ->assertSee('STATUS: COMPLETED')
        ->assertSee('Organic Agriculture Production NC II')
        ->assertSee($fixture['institution']->name)
        ->assertDontSee('Grade:')
        ->assertDontSee('Score:');
});

test('staff can record not completed with pending units and a reason', function () {
    $fixture = createTrainingCertificateFixture(false);

    $this->actingAs($fixture['focal']);

    Livewire::test('certification.index')
        ->set('reasons.'.$fixture['application']->id, 'Competency tasks and unit verification are still pending.')
        ->call('markNotCompleted', $fixture['application']->id)
        ->assertHasNoErrors();

    $certificate = TrainingCertificate::query()
        ->where('enrollment_application_id', $fixture['application']->id)
        ->firstOrFail();

    expect($certificate->status)->toBe('not_completed')
        ->and($certificate->certificate_number)->toBeNull()
        ->and($certificate->reason)->toContain('still pending')
        ->and($certificate->pending_units)->toContain('Unit 1 — Prepare organic production area');
});

test('focal person cannot certify a trainee from an unassigned institution', function () {
    $fixture = createTrainingCertificateFixture(false, 'MIF');
    $otherInstitution = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);

    $fixture['focal']->institutions()->detach();
    $fixture['focal']->institutions()->attach($otherInstitution->id, ['is_primary' => true]);

    $this->actingAs($fixture['focal']);

    Livewire::test('certification.index')
        ->call('issueCertificate', $fixture['application']->id)
        ->assertForbidden();
});

test('scholar cannot access certification management and cannot view another scholars certificate', function () {
    $fixture = createTrainingCertificateFixture(true);
    $certificate = TrainingCertificate::create([
        'enrollment_application_id' => $fixture['application']->id,
        'certificate_number' => 'TC-'.now()->format('Y').'-'.Str::upper(Str::random(12)),
        'status' => 'completed',
        'issued_by_user_id' => $fixture['focal']->id,
        'issued_at' => now(),
    ]);

    $otherScholar = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $this->actingAs($otherScholar)
        ->get(route('certification.index'))
        ->assertForbidden();

    $this->actingAs($otherScholar)
        ->get(route('certificates.print', $certificate))
        ->assertForbidden();
});
