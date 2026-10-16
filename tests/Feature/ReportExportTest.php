<?php

use App\Models\AttendanceRecord;
use App\Models\CompetencyProgress;
use App\Models\CompetencyTask;
use App\Models\CompetencyUnit;
use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function reportTestInstitution(string $code): Institution
{
    return Institution::create([
        'code' => $code,
        'name' => $code.' Training Institution',
        'is_active' => true,
    ]);
}

function reportTestApplication(Institution $institution, ?Course $course, string $reference, string $name): EnrollmentApplication
{
    return EnrollmentApplication::create([
        'reference_no' => $reference,
        'institution_id' => $institution->id,
        'course_id' => $course?->id,
        'learner_name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        'application_status' => 'confirmed',
        'record_completeness' => 100,
        'confirmed_at' => now(),
        'scholar_id' => 'SCH-'.$reference,
    ]);
}

it('exports enrollment CSV for only the registrar assigned institution', function () {
    $bst = reportTestInstitution('BST');
    $mif = reportTestInstitution('MIF');
    reportTestApplication($bst, null, 'REF-BST-001', 'BST Learner');
    reportTestApplication($mif, null, 'REF-MIF-001', 'MIF Learner');

    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);
    $registrar->institutions()->attach($bst->id, ['is_primary' => true]);

    $response = $this->actingAs($registrar)->get(route('reports.export', ['type' => 'enrollments']));
    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.csv');

    $csv = $response->streamedContent();
    expect($csv)->toContain('BST Learner')
        ->and($csv)->not->toContain('MIF Learner')
        ->and($csv)->toContain('Record Completeness (%)');
});

it('exports competency statuses without grades or numerical scores', function () {
    $institution = reportTestInstitution('BST');
    $course = Course::create([
        'institution_id' => $institution->id,
        'code' => 'OAP-NCII',
        'name' => 'Organic Agriculture Production NC II',
        'training_hours' => 232,
        'is_active' => true,
    ]);
    $application = reportTestApplication($institution, $course, 'REF-OAP-001', 'Competency Learner');
    $unit = CompetencyUnit::create([
        'course_id' => $course->id,
        'unit_number' => 1,
        'name' => 'Prepare organic production area',
        'sort_order' => 1,
    ]);
    $task = CompetencyTask::create([
        'competency_unit_id' => $unit->id,
        'chart_type' => 'progress',
        'task_number' => 1,
        'name' => 'Prepare work area',
        'sort_order' => 1,
    ]);
    CompetencyProgress::create([
        'enrollment_application_id' => $application->id,
        'competency_task_id' => $task->id,
        'status' => 'competent',
    ]);
    $admin = User::factory()->create(['role' => 'administrator', 'account_status' => 'active']);

    $response = $this->actingAs($admin)->get(route('reports.export', ['type' => 'competency-progress']));
    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('Competency Status')
        ->and($csv)->toContain('competent')
        ->and($csv)->toContain('Prepare work area')
        ->and(strtolower($csv))->not->toContain('grade')
        ->and(strtolower($csv))->not->toContain('score');
});

it('exports attendance CSV and denies scholars', function () {
    $institution = reportTestInstitution('BST');
    $application = reportTestApplication($institution, null, 'REF-ATT-001', 'Attendance Learner');
    AttendanceRecord::create([
        'enrollment_application_id' => $application->id,
        'attendance_date' => '2026-10-15',
        'status' => 'present',
        'remarks' => 'On time',
    ]);
    $admin = User::factory()->create(['role' => 'administrator', 'account_status' => 'active']);

    $response = $this->actingAs($admin)->get(route('reports.export', ['type' => 'attendance']));
    $response->assertOk();
    expect($response->streamedContent())->toContain('Attendance Status')
        ->and($response->streamedContent())->toContain('Attendance Learner')
        ->and($response->streamedContent())->toContain('On time');

    $scholar = User::factory()->create(['role' => 'scholar', 'account_status' => 'active']);
    $this->actingAs($scholar)->get(route('reports.export', ['type' => 'attendance']))->assertForbidden();
});
