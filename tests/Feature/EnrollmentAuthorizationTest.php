<?php

use App\Models\User;
use Livewire\Livewire;
use App\Models\Institution;

test('scholar cannot access enrollment monitoring', function () {
    $scholar = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $this->actingAs($scholar);

    $this->get(route('enrollments.index'))
        ->assertForbidden();
});

test('inactive registrar cannot access enrollment monitoring', function () {
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'inactive',
    ]);

    $this->actingAs($registrar);

    $this->get(route('enrollments.index'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});


 