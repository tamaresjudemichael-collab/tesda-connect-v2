<?php

use App\Models\User;

test('registrar workflow tools are available to an active registrar', function () {
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $this->actingAs($registrar);

    foreach ([
        'registrar.account-requests',
        'registrar.intake',
        'registrar.forward-batches',
        'registrar.student-records',
        'registrar.accounts',
        'registrar.institutions',
    ] as $routeName) {
        $this->get(route($routeName))->assertOk();
    }
});

test('non-registrar accounts cannot access registrar workflow tools', function () {
    $scholar = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $this->actingAs($scholar);

    foreach ([
        'registrar.account-requests',
        'registrar.intake',
        'registrar.forward-batches',
        'registrar.student-records',
        'registrar.accounts',
        'registrar.institutions',
    ] as $routeName) {
        $this->get(route($routeName))->assertForbidden();
    }
});

test('administrator can view forwarded batches but registrar cannot access that admin queue route', function () {
    $administrator = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $this->actingAs($administrator)->get(route('system.forwarded-batches'))->assertOk();

    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $this->actingAs($registrar)->get(route('system.forwarded-batches'))->assertForbidden();
});
