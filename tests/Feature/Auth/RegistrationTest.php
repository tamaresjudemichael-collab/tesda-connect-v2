<?php

use App\Models\Institution;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register as active applicants and are linked to their selected institution', function () {
    $institution = Institution::create([
        'code' => 'TEST',
        'name' => 'Test Institution',
        'is_active' => true,
    ]);

    $response = $this->post(route('register.store'), [
        'institution_id' => $institution->id,
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->role)->toBe('applicant')
        ->and($user->account_status)->toBe('active')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->institutions()->whereKey($institution->id)->exists())->toBeTrue()
        ->and((bool) $user->institutions()->first()->pivot->is_primary)->toBeTrue();
});

test('registration rejects inactive institutions', function () {
    $institution = Institution::create([
        'code' => 'INACT',
        'name' => 'Inactive Institution',
        'is_active' => false,
    ]);

    $response = $this->from(route('register'))->post(route('register.store'), [
        'institution_id' => $institution->id,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('register'))
        ->assertSessionHasErrors('institution_id');

    $this->assertDatabaseMissing('users', [
        'email' => 'jane@example.com',
    ]);
});
