<?php

use App\Models\User;

test('registrar sidebar consolidates links that use the same page or route', function () {
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $response = $this->actingAs($registrar)->get(route('dashboard'))->assertOk();

    foreach ([
        'DASHBOARD',
        'Dashboard &amp; Recent Submissions',
        'Account Requests',
        'ENROLLMENT MANAGEMENT',
        'Review / Verify / Confirm Enrollments',
        'Intake Acceptance &amp; Quotas',
        'Forward Confirmed Batch to Admin',
        'STUDENT RECORDS',
        'Student Records (Search, Update &amp; Archive)',
        'ANNOUNCEMENTS',
        'Manage Announcements',
        'ACCOUNT MANAGEMENT',
        'Staff Accounts (Passwords, Verification &amp; Status)',
        'INSTITUTIONS',
        'Institution Records &amp; Side-by-Side Summary',
    ] as $label) {
        $response->assertSee($label, false);
    }

    foreach ([
        'data-tesda-sidebar-group="registrar-dashboard"',
        'data-tesda-sidebar-group="registrar-enrollment"',
        'data-tesda-sidebar-group="registrar-student-records"',
        'data-tesda-sidebar-group="registrar-announcements"',
        'data-tesda-sidebar-group="registrar-account-management"',
        'data-tesda-sidebar-group="registrar-institutions"',
    ] as $group) {
        $response->assertSee($group, false);
    }

    // Verify that each merged destination appears only once in the Registrar sidebar source.
    $source = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));
    $start = strpos($source, '@elseif ($user->hasRole(\'registrar\'))');
    expect($start)->not->toBeFalse();
    $end = strpos($source, '@else', $start + 1);
    expect($end)->not->toBeFalse();
    $registrarBlock = substr($source, $start, $end - $start);

    foreach ([
        "route('dashboard')",
        "route('registrar.account-requests')",
        "route('enrollments.index')",
        "route('registrar.intake')",
        "route('registrar.forward-batches')",
        "route('registrar.student-records')",
        "route('announcements.index')",
        "route('registrar.accounts')",
        "route('registrar.institutions')",
    ] as $destination) {
        expect(substr_count($registrarBlock, $destination))->toBe(1, "Expected one sidebar link to {$destination}");
    }

    foreach ([
        'Verify Records',
        'Newest 15 submissions',
        'Pending approvals',
        'Post Announcement',
        'Review Enrollments',
        'Confirm Verified Records',
        'Master List / Search',
        'Update Learner Records',
        'Archived Records',
        'Active & Past Announcements',
        'Create / Post Announcement',
        'Edit / Retract Announcement',
        'View Counts & Recent Viewers',
        'PDF / Image attachments',
        'Staff password reset',
        'Verify staff / no expiry',
        'Manage account status',
        'Mamburao Integrated Farm (MIF)',
        'Balli School of Technology (BST)',
        'Side-by-side summary',
    ] as $duplicateLabel) {
        expect($registrarBlock)->not->toContain($duplicateLabel);
    }

    $response
        ->assertSee(route('registrar.account-requests'), false)
        ->assertSee(route('registrar.intake'), false)
        ->assertSee(route('registrar.forward-batches'), false)
        ->assertDontSee('Not available yet');

    // Registrar navigation must not expose Administrator-only areas.
    $response
        ->assertDontSee(route('system.accounts'), false)
        ->assertDontSee(route('system.institutions'), false)
        ->assertDontSee(route('system.activity'), false)
        ->assertDontSee('SYSTEM MONITORING')
        ->assertDontSee('REPORTS & OVERSIGHT');
});

test('non-registrar roles do not receive the registrar-only navigation groups', function () {
    $learner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $response = $this->actingAs($learner)->get(route('dashboard'))->assertOk();

    $response
        ->assertSee('DASHBOARD')
        ->assertSee('ENROLLMENT SUBMISSION')
        ->assertDontSee('ENROLLMENT MANAGEMENT')
        ->assertDontSee('STUDENT RECORDS')
        ->assertDontSee('ACCOUNT MANAGEMENT')
        ->assertDontSee('Institution Records &amp; Side-by-Side Summary', false);
});

