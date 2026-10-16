<?php

use App\Models\Announcement;
use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Models\User;
use Illuminate\Support\Str;

function makeStudentScholarWorkspaceFixture(): array
{
    $institution = Institution::create([
        'code' => 'SCH'.Str::upper(Str::random(4)),
        'name' => 'Student Workspace Institution',
        'is_active' => true,
    ]);

    $course = Course::create([
        'institution_id' => $institution->id,
        'code' => 'COURSE-'.Str::upper(Str::random(6)),
        'name' => 'Organic Agriculture Production NC II',
        'qualification_level' => 'NC II',
        'training_hours' => 232,
        'is_active' => true,
    ]);

    $batch = TrainingBatch::create([
        'institution_id' => $institution->id,
        'course_id' => $course->id,
        'code' => 'BATCH-'.Str::upper(Str::random(6)),
        'name' => 'OAP Scholar Batch',
        'capacity' => 25,
        'starts_at' => now()->toDateString(),
        'ends_at' => now()->addMonths(5)->toDateString(),
        'next_class_at' => now()->addDays(3)->setTime(9, 30),
        'venue' => 'MIF Training Hall',
        'status' => 'in_training',
    ]);

    $learner = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $application = EnrollmentApplication::create([
        'reference_no' => 'STU-'.Str::upper(Str::random(14)),
        'learner_user_id' => $learner->id,
        'institution_id' => $institution->id,
        'course_id' => $course->id,
        'training_batch_id' => $batch->id,
        'learner_name' => $learner->name,
        'email' => $learner->email,
        'application_status' => 'confirmed',
        'record_completeness' => 100,
        'submitted_at' => now()->subDays(20),
        'verified_at' => now()->subDays(19),
        'confirmed_at' => now()->subDays(18),
        'scholar_id' => 'SCH-'.now()->format('Y').'-'.Str::upper(Str::random(6)),
    ]);

    return compact('institution', 'course', 'batch', 'learner', 'application');
}

test('student dashboard shows status next class venue batch submission deadline and unread count', function () {
    $fixture = makeStudentScholarWorkspaceFixture();

    $deadline = Announcement::query()->create([
        'institution_id' => $fixture['institution']->id,
        'author_user_id' => null,
        'title' => 'Submit updated learner details',
        'body' => 'Please check your current information before the deadline.',
        'audience' => 'scholar',
        'published_at' => now()->subMinute(),
        'deadline_at' => now()->addDays(5)->setTime(17, 0),
    ]);

    $this->actingAs($fixture['learner'])
        ->get(route('learner.portal'))
        ->assertOk()
        ->assertSee('Student / Scholar Dashboard')
        ->assertSee('In Training')
        ->assertSee('Next Class')
        ->assertSee('MIF Training Hall')
        ->assertSee('OAP Scholar Batch')
        ->assertSee('Submission Deadlines')
        ->assertSee('Submit updated learner details')
        ->assertSee('Unread Announcements')
        ->assertSee('1 unread');

    $view = $deadline->views()->first();

    expect($deadline->views()->count())->toBe(1)
        ->and($view?->read_at)->toBeNull();
});

test('student requirements page lists mandatory and conditional documents and official blank form link', function () {
    $learner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $this->actingAs($learner)
        ->get(route('learner.requirements'))
        ->assertOk()
        ->assertSee('Birth Certificate')
        ->assertSee('Transcript of Records (TOR)')
        ->assertSee('Marriage Contract')
        ->assertSee('MIS 03-01')
        ->assertSee('www.tesda.gov.ph', false);
});

test('focal person cannot access student-only enrollment requirements page', function () {
    $focal = User::factory()->create([
        'role' => 'focal_person',
        'account_status' => 'active',
    ]);

    $this->actingAs($focal)
        ->get(route('learner.requirements'))
        ->assertForbidden();
});
