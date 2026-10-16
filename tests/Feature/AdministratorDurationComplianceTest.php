<?php

use App\Models\Course;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Models\User;

function durationFixture(): array
{
    $institution = Institution::create(['code' => 'MIF', 'name' => 'MIF Training Center', 'is_active' => true]);
    $course = Course::create(['institution_id' => $institution->id, 'code' => 'BPP-NC-II', 'name' => 'Bread and Pastry Production NC II', 'training_hours' => 141, 'is_active' => true]);
    return [$institution, $course];
}

test('duration compliance report flags batches under and over course target', function () {
    $admin = User::factory()->create(['role' => 'administrator', 'account_status' => 'active']);
    [$institution, $course] = durationFixture();

    TrainingBatch::create(['institution_id' => $institution->id, 'course_id' => $course->id, 'code' => 'MIF-BPP-01', 'name' => 'Under-hours batch', 'capacity' => 20, 'scheduled_hours' => 120, 'starts_at' => today()->toDateString(), 'ends_at' => today()->addDays(20)->toDateString(), 'status' => 'upcoming']);
    TrainingBatch::create(['institution_id' => $institution->id, 'course_id' => $course->id, 'code' => 'MIF-BPP-02', 'name' => 'Over-hours batch', 'capacity' => 20, 'scheduled_hours' => 150, 'starts_at' => today()->toDateString(), 'ends_at' => today()->addDays(25)->toDateString(), 'status' => 'upcoming']);

    $this->actingAs($admin)->get(route('system.duration-compliance'))
        ->assertOk()->assertSee('Duration Compliance')->assertSee('Under-hours batch')->assertSee('Under target')->assertSee('Over-hours batch')->assertSee('Over target');
});

test('non-administrator cannot open duration compliance report', function () {
    $registrar = User::factory()->create(['role' => 'registrar', 'account_status' => 'active']);
    $this->actingAs($registrar)->get(route('system.duration-compliance'))->assertForbidden();
});
