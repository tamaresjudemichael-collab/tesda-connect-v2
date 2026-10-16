<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Models\User;
use Livewire\Livewire;

test('an inactive authenticated user is logged out from the dashboard', function () {
    $user = User::factory()->create([
        'account_status' => 'inactive',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('an inactive authenticated user cannot access protected settings pages', function (string $routeName) {
    $user = User::factory()->create([
        'account_status' => 'inactive',
    ]);

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertRedirect(route('login'));

    $this->assertGuest();
})->with([
    'profile settings' => 'profile.edit',
    'appearance settings' => 'appearance.edit',
]);

test('an active authenticated user retains dashboard and settings access', function () {
    $user = User::factory()->create([
        'account_status' => 'active',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    $this->get(route('profile.edit'))
        ->assertOk();
});

test('guests are still redirected to login for protected routes', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('the active account guard persists on Livewire update requests', function () {
    expect(Livewire::getPersistentMiddleware())
        ->toContain(EnsureActiveAccount::class);
});