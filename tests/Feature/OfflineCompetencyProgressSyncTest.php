<?php

use App\Models\CompetencyProgress;
use App\Models\CompetencyTask;
use App\Models\CompetencyUnit;
use App\Models\CompetencyUnitReview;
use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function createOfflineCompetencySyncFixture(): array
{
    $suffix = strtoupper(Str::random(6));
    $institution = Institution::create([
        'code' => 'SYNC-'.$suffix,
        'name' => 'Offline Sync Institution '.$suffix,
        'is_active' => true,
    ]);
    $course = Course::create([
        'institution_id' => $institution->id,
        'code' => 'SYNC-COURSE-'.$suffix,
        'name' => 'Organic Agriculture Production NC II',
        'qualification_level' => 'NC II',
        'training_hours' => 232,
        'is_active' => true,
    ]);
    $batch = TrainingBatch::create([
        'institution_id' => $institution->id,
        'course_id' => $course->id,
        'code' => 'SYNC-BATCH-'.$suffix,
        'name' => 'Offline Sync Batch '.$suffix,
        'capacity' => 25,
        'starts_at' => now()->subWeek()->toDateString(),
        'ends_at' => now()->addMonths(5)->toDateString(),
        'status' => 'in_training',
    ]);
    $learner = User::factory()->create(['role' => 'scholar', 'account_status' => 'active']);
    $application = EnrollmentApplication::create([
        'reference_no' => 'SYNC-APP-'.$suffix,
        'learner_user_id' => $learner->id,
        'institution_id' => $institution->id,
        'course_id' => $course->id,
        'training_batch_id' => $batch->id,
        'learner_name' => $learner->name,
        'email' => $learner->email,
        'application_status' => 'confirmed',
        'record_completeness' => 100,
        'confirmed_at' => now(),
        'scholar_id' => 'SYNC-'.$suffix,
    ]);
    $unit = CompetencyUnit::create([
        'course_id' => $course->id,
        'unit_number' => 1,
        'name' => 'Prepare for training',
        'sort_order' => 1,
    ]);
    $task = CompetencyTask::create([
        'competency_unit_id' => $unit->id,
        'chart_type' => 'progress',
        'task_number' => 1,
        'name' => 'Identify training requirements',
        'sort_order' => 1,
    ]);
    $focal = User::factory()->create(['role' => 'focal_person', 'account_status' => 'active']);
    $focal->institutions()->attach($institution->id, ['is_primary' => true]);

    return compact('institution', 'course', 'batch', 'learner', 'application', 'unit', 'task', 'focal');
}

function offlineCompetencyMutation(array $fixture, array $overrides = []): array
{
    return array_merge([
        'mutation_id' => (string) Str::uuid(),
        'enrollment_id' => $fixture['application']->id,
        'task_id' => $fixture['task']->id,
        'status' => 'competent',
        'base_status' => 'not_assessed',
        'base_updated_at' => null,
    ], $overrides);
}

test('offline competency mutation syncs and repeated delivery is idempotent', function () {
    $fixture = createOfflineCompetencySyncFixture();
    $this->actingAs($fixture['focal']);
    $mutation = offlineCompetencyMutation($fixture);

    $this->postJson(route('offline-sync.competency-progress'), ['mutations' => [$mutation]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'synced')
        ->assertJsonPath('results.0.server_status', 'competent');

    $this->postJson(route('offline-sync.competency-progress'), ['mutations' => [$mutation]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'synced');

    expect(CompetencyProgress::query()
        ->where('enrollment_application_id', $fixture['application']->id)
        ->where('competency_task_id', $fixture['task']->id)
        ->count())->toBe(1)
        ->and(DB::table('offline_sync_mutations')->where('user_id', $fixture['focal']->id)->count())->toBe(1);
});

test('offline competency sync reports a conflict when the task changed on the server', function () {
    $fixture = createOfflineCompetencySyncFixture();
    $current = CompetencyProgress::create([
        'enrollment_application_id' => $fixture['application']->id,
        'competency_task_id' => $fixture['task']->id,
        'status' => 'not_yet_competent',
        'assessed_by' => $fixture['focal']->id,
        'assessed_at' => now()->subMinute(),
    ]);
    DB::table('competency_progress')->where('id', $current->id)
        ->update(['updated_at' => now()->subMinute()->format('Y-m-d H:i:s')]);

    $this->actingAs($fixture['focal'])
        ->postJson(route('offline-sync.competency-progress'), [
            'mutations' => [offlineCompetencyMutation($fixture, ['status' => 'competent'])],
        ])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'conflict')
        ->assertJsonPath('results.0.server_status', 'not_yet_competent');

    expect($current->fresh()->status)->toBe('not_yet_competent');
});

test('offline competency sync rejects a focal person outside the assigned institution', function () {
    $fixture = createOfflineCompetencySyncFixture();
    $otherInstitution = Institution::create([
        'code' => 'OTHER-'.strtoupper(Str::random(6)),
        'name' => 'Other institution',
        'is_active' => true,
    ]);
    $fixture['focal']->institutions()->detach($fixture['institution']->id);
    $fixture['focal']->institutions()->attach($otherInstitution->id, ['is_primary' => true]);

    $this->actingAs($fixture['focal'])
        ->postJson(route('offline-sync.competency-progress'), [
            'mutations' => [offlineCompetencyMutation($fixture)],
        ])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'rejected');

    expect(CompetencyProgress::count())->toBe(0);
});

test('offline competency sync cannot update a locked unit', function () {
    $fixture = createOfflineCompetencySyncFixture();
    CompetencyUnitReview::create([
        'enrollment_application_id' => $fixture['application']->id,
        'competency_unit_id' => $fixture['unit']->id,
        'status' => 'verified',
        'verified_by' => $fixture['focal']->id,
        'verified_at' => now(),
        'is_locked' => true,
    ]);

    $this->actingAs($fixture['focal'])
        ->postJson(route('offline-sync.competency-progress'), [
            'mutations' => [offlineCompetencyMutation($fixture)],
        ])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'rejected');

    expect(CompetencyProgress::count())->toBe(0);
});

test('scholars cannot submit offline competency mutations', function () {
    $fixture = createOfflineCompetencySyncFixture();

    $this->actingAs($fixture['learner'])
        ->postJson(route('offline-sync.competency-progress'), [
            'mutations' => [offlineCompetencyMutation($fixture)],
        ])
        ->assertForbidden();
});

test('offline competency queue is integrated only with the individual status controls and public sync script', function () {
    expect(file_exists(public_path('offline-competency-sync.js')))->toBeTrue()
        ->and(file_get_contents(resource_path('views/livewire/progress/competency.blade.php')))->toContain('data-offline-competency-status')
        ->and(file_get_contents(public_path('offline-competency-sync.js')))->toContain('IndexedDB')
        ->and(file_get_contents(public_path('offline-competency-sync.js')))->toContain('syncState');
});
