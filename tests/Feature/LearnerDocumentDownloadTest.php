<?php

use App\Models\Institution;
use App\Models\LearnerDocument;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function createLearnerDocumentForDownloadTest(
    User $owner,
    Institution $institution
): LearnerDocument {
    $profile = LearnerProfile::create([
        'user_id' => $owner->id,
        'institution_id' => $institution->id,
        'last_name' => 'Test',
        'first_name' => 'Learner',
    ]);

    $path = "learner-documents/{$owner->id}/document.pdf";

    Storage::disk('local')->put($path, 'Test document content');

    return LearnerDocument::create([
        'user_id' => $owner->id,
        'learner_profile_id' => $profile->id,
        'document_type' => 'valid_id',
        'original_name' => 'document.pdf',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'size' => 21,
        'status' => 'pending',
    ]);
}

function createInstitutionForDownloadTest(): Institution {
    return Institution::create([
        'code' => 'TEST',
        'name' => 'Test Institution',
        'is_active' => true,
    ]);
}

test('applicant can download their own document', function () {
    $user = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $institution = createInstitutionForDownloadTest();

    $document = createLearnerDocumentForDownloadTest(
        $user,
        $institution
    );

    $this->actingAs($user)
        ->get(route('learner-documents.download', $document))
        ->assertOk();
});

test('applicant cannot download another users document', function () {
    $owner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $otherUser = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $institution = createInstitutionForDownloadTest();

    $document = createLearnerDocumentForDownloadTest(
        $owner,
        $institution
    );

    $this->actingAs($otherUser)
        ->get(route('learner-documents.download', $document))
        ->assertForbidden();
});

test('registrar can download a document from an assigned institution', function () {
    $owner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $institution = createInstitutionForDownloadTest();

    $registrar->institutions()->attach(
        $institution->id,
        ['is_primary' => true]
    );

    $document = createLearnerDocumentForDownloadTest(
        $owner,
        $institution
    );

    $this->actingAs($registrar)
        ->get(route('learner-documents.download', $document))
        ->assertOk();
});

test('registrar cannot download a document from an unassigned institution', function () {
    $owner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $documentInstitution = createInstitutionForDownloadTest();

    $assignedInstitution = Institution::create([
        'code' => 'OTHER',
        'name' => 'Other Institution',
        'is_active' => true,
    ]);

    $registrar->institutions()->attach(
        $assignedInstitution->id,
        ['is_primary' => true]
    );

    $document = createLearnerDocumentForDownloadTest(
        $owner,
        $documentInstitution
    );

    $this->actingAs($registrar)
        ->get(route('learner-documents.download', $document))
        ->assertForbidden();
});

test('inactive applicant cannot download their own document', function () {
    $user = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'inactive',
    ]);

    $institution = createInstitutionForDownloadTest();

    $document = createLearnerDocumentForDownloadTest(
        $user,
        $institution
    );

    $this->actingAs($user)
        ->get(route('learner-documents.download', $document))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('guest cannot download a learner document', function () {
    $owner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $institution = createInstitutionForDownloadTest();

    $document = createLearnerDocumentForDownloadTest(
        $owner,
        $institution
    );

    $this->get(route('learner-documents.download', $document))
        ->assertRedirect(route('login'));
});

test('applicant cannot download a document using another folder path', function () {
    $user = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $institution = createInstitutionForDownloadTest();

    $document = createLearnerDocumentForDownloadTest(
        $user,
        $institution
    );

    $document->update([
        'path' => 'learner-documents/999/other-file.pdf',
    ]);

    $this->actingAs($user)
        ->get(route('learner-documents.download', $document))
        ->assertForbidden();
});


test('administrator can download a learner document', function () {
    $owner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $administrator = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $institution = createInstitutionForDownloadTest();

    $document = createLearnerDocumentForDownloadTest(
        $owner,
        $institution
    );

    $this->actingAs($administrator)
        ->get(route('learner-documents.download', $document))
        ->assertOk();
});

test('focal person can download a document from an assigned institution', function () {
    $owner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $focalPerson = User::factory()->create([
        'role' => 'focal_person',
        'account_status' => 'active',
    ]);

    $institution = createInstitutionForDownloadTest();

    $focalPerson->institutions()->attach(
        $institution->id,
        ['is_primary' => true]
    );

    $document = createLearnerDocumentForDownloadTest(
        $owner,
        $institution
    );

    $this->actingAs($focalPerson)
        ->get(route('learner-documents.download', $document))
        ->assertOk();
});

test('focal person cannot download a document from an unassigned institution', function () {
    $owner = User::factory()->create([
        'role' => 'applicant',
        'account_status' => 'active',
    ]);

    $focalPerson = User::factory()->create([
        'role' => 'focal_person',
        'account_status' => 'active',
    ]);

    $documentInstitution = createInstitutionForDownloadTest();

    $otherInstitution = Institution::create([
        'code' => 'OTHER',
        'name' => 'Other Institution',
        'is_active' => true,
    ]);

    $focalPerson->institutions()->attach(
        $otherInstitution->id,
        ['is_primary' => true]
    );

    $document = createLearnerDocumentForDownloadTest(
        $owner,
        $documentInstitution
    );

    $this->actingAs($focalPerson)
        ->get(route('learner-documents.download', $document))
        ->assertForbidden();
});