<?php

use App\Models\Institution;
use App\Models\User;

beforeEach(function (): void {
    $this->institution = Institution::create([
        'code' => 'FOC', 'name' => 'Focal Test Institution', 'address' => 'Test Address',
        'contact_email' => 'focal@example.test', 'contact_number' => '09170000000', 'is_active' => true,
    ]);
});

test('focal person can reach course, document, and forms workspaces for assigned institution', function (): void {
    $user = User::factory()->create(['role' => 'focal_person', 'account_status' => 'active']);
    $user->institutions()->attach($this->institution->id, ['is_primary' => true]);

    $this->actingAs($user)->get(route('focal.courses'))->assertOk();
    $this->actingAs($user)->get(route('focal.documents'))->assertOk();
    $this->actingAs($user)->get(route('focal.forms'))->assertOk();
    $this->actingAs($user)->get(route('focal.forms.download', ['form' => 'trainee-roster']))
        ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('non-focal roles cannot use focal course and form pages', function (): void {
    $user = User::factory()->create(['role' => 'scholar', 'account_status' => 'active']);
    $this->actingAs($user)->get(route('focal.courses'))->assertForbidden();
    $this->actingAs($user)->get(route('focal.forms'))->assertForbidden();
});

test('focal forms reject unknown template names', function (): void {
    $user = User::factory()->create(['role' => 'focal_person', 'account_status' => 'active']);
    $user->institutions()->attach($this->institution->id, ['is_primary' => true]);
    $this->actingAs($user)->get(route('focal.forms.download', ['form' => 'arbitrary-file']))->assertNotFound();
});

test('focal person can upload an operational document to the assigned institution', function (): void {
    \Illuminate\Support\Facades\Storage::fake('local');
    $user = User::factory()->create(['role' => 'focal_person', 'account_status' => 'active']);
    $user->institutions()->attach($this->institution->id, ['is_primary' => true]);

    $response = $this->actingAs($user)->post(route('focal.documents.offline-upload'), [
        'institution_id' => $this->institution->id,
        'category' => 'assessment_form',
        'title' => 'Assessment Form Sample',
        'notes' => 'Uploaded during feature test',
        'upload' => \Illuminate\Http\UploadedFile::fake()->create('assessment.pdf', 100, 'application/pdf'),
    ]);

    $response->assertOk()->assertJsonPath('ok', true);
    $this->assertDatabaseHas('operational_documents', [
        'institution_id' => $this->institution->id,
        'uploaded_by_user_id' => $user->id,
        'title' => 'Assessment Form Sample',
        'category' => 'assessment_form',
    ]);
    $stored = \App\Models\OperationalDocument::where('title', 'Assessment Form Sample')->firstOrFail();
    \Illuminate\Support\Facades\Storage::disk('local')->assertExists($stored->path);
});

test('focal person cannot upload an operational document for an unassigned institution', function (): void {
    $user = User::factory()->create(['role' => 'focal_person', 'account_status' => 'active']);
    $outside = Institution::create([
        'code' => 'OUT', 'name' => 'Outside Institution', 'address' => 'Other Address',
        'contact_email' => 'outside@example.test', 'contact_number' => '09170000001', 'is_active' => true,
    ]);
    $user->institutions()->attach($this->institution->id, ['is_primary' => true]);

    $this->actingAs($user)->post(route('focal.documents.offline-upload'), [
        'institution_id' => $outside->id,
        'category' => 'other',
        'title' => 'Unauthorized document',
        'upload' => \Illuminate\Http\UploadedFile::fake()->create('bad.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});
