<?php

use App\Models\User;

test('administrator sidebar is wider on desktop and constrained on narrow screens', function () {
    $administrator = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $response = $this->actingAs($administrator)->get(route('dashboard'))->assertOk();

    $response
        ->assertSee('--sidebar-width: 19rem', false)
        ->assertSee('width: min(19rem, calc(100vw - 1rem))', false)
        ->assertSee('width: min(18rem, calc(100vw - 0.75rem))', false)
        ->assertSee('overflow-x: hidden', false)
        ->assertSee('overflow-x-hidden', false)
        ->assertSee('overflow-wrap: anywhere', false);
});

test('administrator sidebar groups are configured to start collapsed', function () {
    $sidebar = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));

    expect($sidebar)->toBeString();

    foreach ([
        'heading="DASHBOARD" expandable :expanded="false" data-tesda-sidebar-group="dashboard"',
        'heading="ENROLLMENT MONITORING" expandable :expanded="false" data-tesda-sidebar-group="enrollment"',
        'heading="SCHEDULING MANAGEMENT" expandable :expanded="false" data-tesda-sidebar-group="scheduling"',
        'heading="SYSTEM MONITORING" expandable :expanded="false" data-tesda-sidebar-group="system-monitoring"',
        'heading="REPORTS & OVERSIGHT" expandable :expanded="false" data-tesda-sidebar-group="reports"',
    ] as $configuration) {
        expect($sidebar)->toContain($configuration);
    }
});

test('administrator sidebar remembers expanded navigation groups across reloads and Livewire navigation', function () {
    $administrator = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $response = $this->actingAs($administrator)->get(route('dashboard'))->assertOk();

    $response
        ->assertSee('data-tesda-sidebar-group="dashboard"', false)
        ->assertSee('data-tesda-sidebar-group="enrollment"', false)
        ->assertSee('data-tesda-sidebar-group="scheduling"', false)
        ->assertSee('data-tesda-sidebar-group="system-monitoring"', false)
        ->assertSee('data-tesda-sidebar-group="reports"', false)
        ->assertSee('localStorage.getItem(storageKey)', false)
        ->assertSee('localStorage.setItem(storageKey', false)
        ->assertSee('openGroup', false)
        ->assertSee('otherGroup !== group', false)
        ->assertSee('background-color: #ffffff !important', false)
        ->assertSee('color: #102f68 !important', false)
        ->assertSee('livewire:navigated', false)
        ->assertSee('window.requestAnimationFrame', false);
});



test('sidebar typography is normalized across administrator and learner accounts', function () {
    $administrator = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $learner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $this->actingAs($administrator)->get(route('dashboard'))->assertOk()
        ->assertSee('font-size: 1rem !important', false)
        ->assertSee('font-size: 0.875rem !important', false)
        ->assertSee('Use the same typography for every role', false);

    auth()->logout();

    $this->actingAs($learner)->get(route('dashboard'))->assertOk()
        ->assertSee('font-size: 1rem !important', false)
        ->assertSee('font-size: 0.875rem !important', false)
        ->assertSee('Use the same typography for every role', false);
});
