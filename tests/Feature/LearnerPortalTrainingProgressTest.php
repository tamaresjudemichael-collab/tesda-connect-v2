<?php

use App\Models\AttendanceRecord;
use App\Models\CompetencyProgress;
use App\Models\CompetencyTask;
use App\Models\CompetencyUnit;
use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('confirmed learner sees their own competency and attendance status without grade fields', function () {
    $institution = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);

    $course = Course::create([
        'institution_id' => $institution->id,
        'code' => 'OAP-NC-II',
        'name' => 'Organic Agriculture Production NC II',
        'qualification_level' => 'NC II',
        'training_hours' => 232,
        'is_active' => true,
    ]);

    $learner = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $application = EnrollmentApplication::create([
        'reference_no' => 'TC-'.Str::upper(Str::random(16)),
        'learner_user_id' => $learner->id,
        'institution_id' => $institution->id,
        'course_id' => $course->id,
        'learner_name' => $learner->name,
        'email' => $learner->email,
        'application_status' => 'confirmed',
        'record_completeness' => 100,
        'confirmed_at' => now(),
        'scholar_id' => 'BST-'.now()->format('Y').'-'.Str::upper(Str::random(6)),
    ]);

    $unit = CompetencyUnit::create([
        'course_id' => $course->id,
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

    CompetencyProgress::create([
        'enrollment_application_id' => $application->id,
        'competency_task_id' => $task->id,
        'status' => 'competent',
        'assessed_by' => null,
        'assessed_at' => now(),
    ]);

    AttendanceRecord::create([
        'enrollment_application_id' => $application->id,
        'attendance_date' => now()->toDateString(),
        'status' => 'present',
        'remarks' => 'Present during practical session',
        'recorded_by_user_id' => null,
    ]);

    $this->actingAs($learner);

    Livewire::test('learner.portal')
        ->assertSee('Training competency progress')
        ->assertSee('Raise Organic Chicken')
        ->assertSee('Select Healthy Stocks')
        ->assertSee('Competent')
        ->assertSee('My attendance')
        ->assertSee('Present')
        ->assertSee('Present during practical session');
});
