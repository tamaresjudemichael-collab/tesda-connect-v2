<?php

use App\Models\User;

test('registrar sidebar follows the requested six navigation sections', function () {
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $response = $this->actingAs($registrar)->get(route('dashboard'))->assertOk();

    foreach ([
        'DASHBOARD',
        'Pending Enrollments',
        'Recent Submissions',
        'Newest 15 submissions',
        'Account Requests',
        'Pending approvals',
        'Quick Actions',
        'Verify Records',
        'Post Announcement',
        'ENROLLMENT MANAGEMENT',
        'Review Enrollments',
        'Confirm Verified Records',
        'Intake acceptance & quotas',
        'Forward confirmed batch',
        'STUDENT RECORDS',
        'Master List / Search',
        'Update Learner Records',
        'Archived Records',
        'ANNOUNCEMENTS',
        'Active & Past Announcements',
        'Create / Post Announcement',
        'Edit / Retract Announcement',
        'View Counts & Recent Viewers',
        'PDF / Image attachments',
        'ACCOUNT MANAGEMENT',
        'Staff password reset',
        'Verify staff / no expiry',
        'Manage account status',
        'INSTITUTIONS',
        'Mamburao Integrated Farm (MIF)',
        'Balli School of Technology (BST)',
        'Side-by-side summary',
    ] as $label) {
        $response->assertSee($label);
    }

    $response
        ->assertSee('data-tesda-sidebar-group="registrar-dashboard"', false)
        ->assertSee('data-tesda-sidebar-group="registrar-enrollment"', false)
        ->assertSee('data-tesda-sidebar-group="registrar-student-records"', false)
        ->assertSee('data-tesda-sidebar-group="registrar-announcements"', false)
        ->assertSee('data-tesda-sidebar-group="registrar-account-management"', false)
        ->assertSee('data-tesda-sidebar-group="registrar-institutions"', false)
        ->assertSee(route('enrollments.index', ['status' => 'submitted']), false)
        ->assertSee(route('enrollments.index', ['status' => 'verified']), false)
        ->assertSee(route('announcements.index'), false)
        ->assertSee(route('announcements.index', ['section' => 'create']), false)
        ->assertSee(route('announcements.index', ['section' => 'viewership']), false)
        ->assertSee(route('registrar.intake'), false)
        ->assertSee(route('registrar.forward-batches'), false)
        ->assertSee(route('registrar.student-records'), false)
        ->assertSee(route('registrar.accounts', ['action' => 'password']), false)
        ->assertSee(route('registrar.institutions', ['institution' => 'BST']), false)
        ->assertDontSee('Not available yet');

    // Registrar navigation must never expose Administrator-only links.
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

    // Applicants and scholars now have their own Student / Scholar navigation groups.
    $response
        ->assertSee('DASHBOARD')
        ->assertSee('Student / Scholar Dashboard')
        ->assertSee('ENROLLMENT SUBMISSION')
        ->assertSee('View Requirements')
        ->assertSee('Submit Enrollment / Registration Form')
        ->assertDontSee('ENROLLMENT MANAGEMENT')
        ->assertDontSee('STUDENT RECORDS')
        ->assertDontSee('ACCOUNT MANAGEMENT')
        ->assertDontSee('Mamburao Integrated Farm (MIF)');
});
