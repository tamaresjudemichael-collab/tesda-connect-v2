<?php

use App\Models\User;

test('successful login is recorded in activity logs', function () {
    $user = User::factory()->create([
        'account_status' => 'active',
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->id,
        'event' => 'user_logged_in',
    ]);
});

test('logout is recorded in activity logs', function () {
    $user = User::factory()->create([
        'account_status' => 'active',
    ]);

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->id,
        'event' => 'user_logged_out',
    ]);
});

test('administrator can access system activity logs', function () {
    $administrator = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $this->actingAs($administrator)
        ->get(route('system.activity'))
        ->assertOk();
});

test('registrar cannot access system activity logs', function () {
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $this->actingAs($registrar)
        ->get(route('system.activity'))
        ->assertForbidden();
});