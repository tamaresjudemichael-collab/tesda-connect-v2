<?php

use App\Models\User;

it('lets every active application role read the role handbook', function (string $role) {
    $user = User::factory()->create([
        'role' => $role,
        'account_status' => 'active',
    ]);

    $this->actingAs($user)
        ->get(route('user-guide'))
        ->assertOk()
        ->assertSee('User Guide & Role Handbook', false)
        ->assertSee('System Administrator')
        ->assertSee('Registrar')
        ->assertSee('UTPRAS / Focal Person')
        ->assertSee('Applicant')
        ->assertSee('Scholar / Student');
})->with([
    'administrator' => 'administrator',
    'registrar' => 'registrar',
    'focal_person' => 'focal_person',
    'applicant' => 'applicant',
    'scholar' => 'scholar',
]);
