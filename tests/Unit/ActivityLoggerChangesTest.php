<?php

use App\Models\Course;
use App\Support\ActivityLogger;

test('activity logger records only changed selected fields as before and after values', function () {
    $course = new Course([
        'institution_id' => 1,
        'code' => 'OA-NC2',
        'name' => 'Organic Agriculture',
        'qualification_level' => 'NC II',
        'training_hours' => 200,
        'is_active' => true,
    ]);

    $before = ActivityLogger::snapshot($course, ['code', 'name', 'training_hours', 'is_active']);

    $course->name = 'Organic Agriculture NC II';
    $course->is_active = false;

    $changes = ActivityLogger::changes($course, $before);

    expect($changes)->toBe([
        'name' => ['from' => 'Organic Agriculture', 'to' => 'Organic Agriculture NC II'],
        'is_active' => ['from' => true, 'to' => false],
    ]);
});

test('activity logger omits the changes key when no selected value changed', function () {
    $course = new Course(['code' => 'OA-NC2', 'name' => 'Organic Agriculture', 'is_active' => true]);
    $before = ActivityLogger::snapshot($course, ['code', 'name', 'is_active']);

    expect(ActivityLogger::withChanges(['course_id' => 5], ActivityLogger::changes($course, $before)))
        ->toBe(['course_id' => 5]);
});
