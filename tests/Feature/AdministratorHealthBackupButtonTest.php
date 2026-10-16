<?php

use App\Models\User;
use Livewire\Livewire;

it('shows the create backup action on the administrator health page', function () {
    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $this->actingAs($admin)
        ->get(route('system.health'))
        ->assertOk()
        ->assertSee('Create backup')
        ->assertSee('Create full backup')
        ->assertSee('storage/app/backups');
});

it('does not allow non-administrators to access the backup page', function () {
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $this->actingAs($registrar)
        ->get(route('system.health'))
        ->assertForbidden();
});
