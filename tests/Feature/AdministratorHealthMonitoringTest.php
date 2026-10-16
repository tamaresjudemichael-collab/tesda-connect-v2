<?php

use App\Models\User;

test('administrator can view system health checks', function () {
    $admin = User::factory()->create(['role' => 'administrator', 'account_status' => 'active']);
    $this->actingAs($admin)->get(route('system.health'))
        ->assertOk()->assertSee('System Health')->assertSee('Database')->assertSee('Backup status')->assertSee('Storage usage');
});

test('non-administrator cannot view system health checks', function () {
    $registrar = User::factory()->create(['role' => 'registrar', 'account_status' => 'active']);
    $this->actingAs($registrar)->get(route('system.health'))->assertForbidden();
});
