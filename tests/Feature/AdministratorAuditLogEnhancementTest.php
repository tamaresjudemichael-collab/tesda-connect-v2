<?php

use App\Models\ActivityLog;
use App\Models\User;
use Livewire\Livewire;

function makeAuditLog(User $user, string $event, string $description, $createdAt, array $metadata = []): ActivityLog
{
    $log = ActivityLog::create([
        'user_id' => $user->id,
        'event' => $event,
        'description' => $description,
        'metadata' => $metadata ?: null,
    ]);

    $log->created_at = $createdAt;
    $log->updated_at = $createdAt;
    $log->saveQuietly();

    return $log;
}

test('administrator can filter audit logs by date range, module, and action type', function () {
    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    makeAuditLog($admin, 'enrollment_created', 'Current enrollment event', now(), [
        'from' => 'draft',
        'to' => 'submitted',
    ]);
    makeAuditLog($admin, 'user_account_created', 'Older account event', now()->subDays(3));
    makeAuditLog($admin, 'attendance_recorded', 'Different module event', now());

    $this->actingAs($admin);

    Livewire::test('system.activity')
        ->set('startDate', now()->toDateString())
        ->set('endDate', now()->toDateString())
        ->set('moduleFilter', 'Enrollment Monitoring')
        ->set('eventFilter', 'enrollment_created')
        ->assertSee('Current enrollment event')
        ->assertSee('draft')
        ->assertSee('submitted')
        ->assertDontSee('Older account event')
        ->assertDontSee('Different module event');
});

test('administrator can export the filtered audit history to csv', function () {
    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    makeAuditLog($admin, 'enrollment_created', 'Exportable enrollment event', now());

    $this->actingAs($admin);

    Livewire::test('system.activity')
        ->set('moduleFilter', 'Enrollment Monitoring')
        ->call('exportCsv')
        ->assertFileDownloaded();
});

test('registrars cannot view or export administrator audit logs', function () {
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $this->actingAs($registrar)
        ->get(route('system.activity'))
        ->assertForbidden();
});
