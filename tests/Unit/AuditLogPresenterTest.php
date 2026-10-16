<?php

use App\Support\AuditLogPresenter;

test('audit event types map to oversight modules', function () {
    expect(AuditLogPresenter::moduleForEvent('enrollment_created'))->toBe('Enrollment Monitoring')
        ->and(AuditLogPresenter::moduleForEvent('attendance_recorded'))->toBe('Attendance Management')
        ->and(AuditLogPresenter::moduleForEvent('random_event'))->toBe('General');
});

test('audit change values support scalar and structured metadata', function () {
    expect(AuditLogPresenter::auditValue(['from' => 'draft', 'to' => 'submitted'], ['from', 'old_value']))
        ->toBe('draft')
        ->and(AuditLogPresenter::auditValue(['to' => ['status' => 'submitted']], ['to', 'new_value']))
        ->toBe('{"status":"submitted"}')
        ->and(AuditLogPresenter::auditValue(null, ['from', 'old_value']))
        ->toBe('');
});

test('audit log presenter formats field level change metadata', function () {
    $rows = AuditLogPresenter::changeEntries([
        'changes' => [
            'name' => ['from' => 'Old name', 'to' => 'New name'],
            'is_active' => ['from' => true, 'to' => false],
        ],
    ]);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe([
            'field' => 'Name',
            'from' => 'Old name',
            'to' => 'New name',
        ])
        ->and($rows[1])->toBe([
            'field' => 'Is Active',
            'from' => 'true',
            'to' => 'false',
        ]);
});
