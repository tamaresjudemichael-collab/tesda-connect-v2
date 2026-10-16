<?php

use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

function createAttendanceFixture(string $institutionCode = 'BST'): array
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
        'code' => 'BATCH-'.$institutionCode,
        'name' => 'Organic Agriculture Batch',
        'capacity' => 25,
        'starts_at' => now()->subWeek()->toDateString(),
        'ends_at' => now()->addMonths(5)->toDateString(),
        'status' => 'in_training',
    ]);

    $learner = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $application = EnrollmentApplication::create([
        'reference_no' => 'TC-'.strtoupper($institutionCode).'-'.Str::random(10),
        'learner_user_id' => $learner->id,
        'institution_id' => $institution->id,
        'course_id' => $course->id,
        'training_batch_id' => $batch->id,
        'learner_name' => $learner->name,
        'email' => $learner->email,
        'application_status' => 'confirmed',
        'record_completeness' => 100,
        'confirmed_at' => now(),
        'scholar_id' => $institutionCode.'-'.now()->format('Y').'-'.strtoupper(Str::random(6)),
    ]);

    return compact('institution', 'course', 'batch', 'learner', 'application');
}

test('focal person can record attendance for a confirmed trainee in an assigned institution', function () {
    $fixture = createAttendanceFixture();
    $focal = User::factory()->create([
        'role' => 'focal_person',
        'account_status' => 'active',
    ]);
    $focal->institutions()->attach($fixture['institution']->id, ['is_primary' => true]);

    $this->actingAs($focal);

    Livewire::test('attendance.index')
        ->set('batchId', $fixture['batch']->id)
        ->call('loadAttendance')
        ->set('attendanceStatus.'.$fixture['application']->id, 'present')
        ->set('attendanceRemarks.'.$fixture['application']->id, 'On time')
        ->call('save')
        ->assertHasNoErrors();

    $attendance = AttendanceRecord::query()
        ->where('enrollment_application_id', $fixture['application']->id)
        ->first();

    expect($attendance)->not->toBeNull()
        ->and($attendance->status)->toBe('present')
        ->and($attendance->remarks)->toBe('On time')
        ->and($attendance->recorded_by_user_id)->toBe($focal->id);
});

test('focal person cannot load a batch from an unassigned institution', function () {
    $fixture = createAttendanceFixture('MIF');
    $otherInstitution = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);
    $focal = User::factory()->create([
        'role' => 'focal_person',
        'account_status' => 'active',
    ]);
    $focal->institutions()->attach($otherInstitution->id, ['is_primary' => true]);

    $this->actingAs($focal);

    Livewire::test('attendance.index')
        ->set('batchId', $fixture['batch']->id)
        ->assertStatus(403);

    expect(AttendanceRecord::count())->toBe(0);
});

test('scholars cannot access attendance management', function () {
    $scholar = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $this->actingAs($scholar)
        ->get(route('attendance.index'))
        ->assertForbidden();
});
