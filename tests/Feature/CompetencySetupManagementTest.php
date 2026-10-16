<?php

use App\Models\CompetencyProgress;
use App\Models\CompetencyTask;
use App\Models\CompetencyUnit;
use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->institution = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);

    $this->otherInstitution = Institution::create([
        'code' => 'MIF',
        'name' => 'Mamburao Integrated Farm',
        'is_active' => true,
    ]);

    $this->course = Course::create([
        'institution_id' => $this->institution->id,
        'code' => 'OAP-NC-II',
        'name' => 'Organic Agriculture Production NC II',
        'qualification_level' => 'NC II',
        'training_hours' => 232,
        'is_active' => true,
    ]);

    $this->otherCourse = Course::create([
        'institution_id' => $this->otherInstitution->id,
        'code' => 'OTHER-NC-II',
        'name' => 'Other Institution Course',
        'qualification_level' => 'NC II',
        'training_hours' => 100,
        'is_active' => true,
    ]);

    $this->focal = User::factory()->create([
        'role' => 'focal_person',
        'account_status' => 'active',
    ]);
    $this->focal->institutions()->attach($this->institution->id, ['is_primary' => true]);
});

test('focal person can create a competency unit and chart task for an assigned course', function () {
    $this->actingAs($this->focal);

    Livewire::test('competencies.index')
        ->set('selectedCourseId', (string) $this->course->id)
        ->set('unitNumber', '1')
        ->set('unitName', 'Raise Organic Chicken')
        ->set('unitSortOrder', '1')
        ->call('saveUnit')
        ->assertHasNoErrors();

    $unit = CompetencyUnit::query()->where('course_id', $this->course->id)->firstOrFail();

    Livewire::test('competencies.index')
        ->set('selectedCourseId', (string) $this->course->id)
        ->set('taskUnitId', (string) $unit->id)
        ->set('taskChartType', 'progress')
        ->set('taskNumber', '1')
        ->set('taskName', 'Select Healthy Stocks')
        ->set('taskSortOrder', '1')
        ->call('saveTask')
        ->assertHasNoErrors();

    expect(CompetencyTask::query()
        ->where('competency_unit_id', $unit->id)
        ->where('chart_type', 'progress')
        ->where('name', 'Select Healthy Stocks')
        ->exists())->toBeTrue();
});

test('focal person cannot edit competency units from an unassigned institution', function () {
    $unit = CompetencyUnit::create([
        'course_id' => $this->otherCourse->id,
        'unit_number' => 1,
        'name' => 'Private unit',
        'sort_order' => 1,
    ]);

    $this->actingAs($this->focal);

    Livewire::test('competencies.index')
        ->call('editUnit', $unit->id)
        ->assertStatus(404);

    expect($unit->fresh()->name)->toBe('Private unit');
});

test('scholars cannot access competency setup', function () {
    $scholar = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $this->actingAs($scholar)
        ->get(route('competencies.index'))
        ->assertForbidden();
});

test('a competency task with trainee progress cannot be deleted', function () {
    $unit = CompetencyUnit::create([
        'course_id' => $this->course->id,
        'unit_number' => 1,
        'name' => 'Raise Organic Chicken',
        'sort_order' => 1,
    ]);

    $task = CompetencyTask::create([
        'competency_unit_id' => $unit->id,
        'chart_type' => 'progress',
        'task_number' => 1,
        'name' => 'Select Healthy Stocks',
        'sort_order' => 1,
    ]);

    $application = EnrollmentApplication::create([
        'reference_no' => 'TC-'.now()->format('Y').'-COMPTEST01',
        'institution_id' => $this->institution->id,
        'learner_name' => 'Test Scholar',
        'application_status' => 'confirmed',
        'record_completeness' => 100,
        'confirmed_at' => now(),
    ]);

    CompetencyProgress::create([
        'enrollment_application_id' => $application->id,
        'competency_task_id' => $task->id,
        'status' => 'competent',
        'assessed_at' => now(),
    ]);

    $this->actingAs($this->focal);

    Livewire::test('competencies.index')
        ->call('deleteTask', $task->id)
        ->assertHasErrors(['unit']);

    expect(CompetencyTask::query()->whereKey($task->id)->exists())->toBeTrue();
});
