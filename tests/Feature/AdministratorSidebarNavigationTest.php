<?php

use App\Models\User;

test('administrator sidebar follows the five requested navigation sections', function () {
    $administrator = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $response = $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertOk();

    foreach ([
        'DASHBOARD',
        'System Overview',
        'BST ↔ MIF Comparison',
        'Activity Logs',
        'Quick Stats',
        'ENROLLMENT MONITORING',
        'Submissions',
        'Verified Records',
        'Intake Summary',
        'SCHEDULING MANAGEMENT',
        'Create / Edit',
        'Calendar',
        'Duration Compliance',
        'SYSTEM MONITORING',
        'Accounts',
        'Audit Logs',
        'Announcement Monitor',
        'Health',
        'REPORTS & OVERSIGHT',
        'Progress',
        'Completion',
        'Performance',
        'Enrollment CSV',
        'Competency CSV',
        'Attendance CSV',
    ] as $label) {
        $response->assertSee($label);
    }

    $response
        ->assertSee(route('dashboard').'#system-overview', false)
        ->assertSee(route('dashboard').'#bst-mif-comparison', false)
        ->assertSee(route('dashboard').'#quick-stats', false)
        ->assertSee(route('enrollments.index', ['status' => 'submitted']), false)
        ->assertSee(route('enrollments.index', ['status' => 'confirmed']), false)
        ->assertSee('Forwarded Batches')
        ->assertDontSee('Not available yet');
});

test('non-administrator focal navigation shows its workspace and hides administrator oversight sections', function () {
    $focalPerson = User::factory()->create([
        'role' => 'focal_person',
        'account_status' => 'active',
    ]);

    $this->actingAs($focalPerson)
        ->get(route('dashboard'))
        ->assertOk()
        // The focal-person navigation now uses its role-specific workspace groups,
        // rather than the legacy generic MAIN MENU labels.
        ->assertSee('DASHBOARD')
        ->assertSee('Dashboard & Institution Overview')
        ->assertSee('COMPETENCY PROGRESS')
        ->assertSee('Progress Charts, Update, Review & Submit Summary')
        ->assertSee('COURSE MANAGEMENT')
        ->assertSee('Register, Update & Archive Courses')
        ->assertSee('CERTIFICATION MANAGEMENT')
        ->assertSee('Batch Certification & Issue Certificate')
        ->assertSee('Upload / Download Document Library')
        ->assertSee('Batch Scheduling')
        ->assertSee('Reports & Oversight')
        ->assertDontSee('SYSTEM MONITORING')
        ->assertDontSee('BST ↔ MIF Comparison')
        ->assertDontSee('REGISTRAR STUDENT RECORDS')
        ->assertDontSee(route('system.accounts'), false)
        ->assertDontSee(route('system.institutions'), false);
});
