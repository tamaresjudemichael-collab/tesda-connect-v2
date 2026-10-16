<?php

use App\Models\Course;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Models\User;

function calendarFixture(): array
{
    $institution = Institution::create(['code' => 'BST', 'name' => 'BST Training Center', 'is_active' => true]);
    $course = Course::create(['institution_id' => $institution->id, 'code' => 'OAP-NC-II', 'name' => 'Organic Agriculture Production NC II', 'training_hours' => 240, 'is_active' => true]);
    return [$institution, $course];
}

test('administrator calendar shows scheduled batches and overlap alerts', function () {
    $admin = User::factory()->create(['role' => 'administrator', 'account_status' => 'active']);
    [$institution, $course] = calendarFixture();
    $startsAt = today()->startOfMonth()->addDays(4);
    $endsAt = $startsAt->copy()->addDays(8);

    foreach ([['BST-OAP-01', 'OAP Batch 1'], ['BST-OAP-02', 'OAP Batch 2']] as [$code, $name]) {
        TrainingBatch::create([
            'institution_id' => $institution->id, 'course_id' => $course->id,
            'code' => $code, 'name' => $name, 'capacity' => 25,
            'scheduled_hours' => 240, 'starts_at' => $startsAt->toDateString(),
            'ends_at' => $endsAt->toDateString(), 'status' => 'upcoming',
        ]);
    }

    $this->actingAs($admin)->get(route('system.calendar'))
        ->assertOk()->assertSee('Training Calendar')->assertSee('OAP Batch 1')->assertSee('Potential schedule overlap');
});

test('non-administrator cannot open training calendar', function () {
    $registrar = User::factory()->create(['role' => 'registrar', 'account_status' => 'active']);
    $this->actingAs($registrar)->get(route('system.calendar'))->assertForbidden();
});
