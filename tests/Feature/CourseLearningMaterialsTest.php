<?php

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function makeMaterialCourse(Institution $institution, string $code = 'MIF-TEST'): Course
{
    return Course::query()->create([
        'institution_id' => $institution->id,
        'code' => $code,
        'name' => 'Test Training Course',
        'is_active' => true,
    ]);
}

function makeMaterialInstitution(string $code, string $name): Institution
{
    return Institution::query()->create(['code' => $code, 'name' => $name, 'is_active' => true]);
}

test('focal person can upload course materials only for an assigned institution', function (): void {
    Storage::fake('local');
    $institution = makeMaterialInstitution('MIF', 'Mamburao Integrated Farm');
    $otherInstitution = makeMaterialInstitution('BST', 'Balli School of Technology');
    $course = makeMaterialCourse($institution);
    $otherCourse = makeMaterialCourse($otherInstitution, 'BST-TEST');
    $focal = User::factory()->create(['role' => 'focal_person', 'account_status' => 'active']);
    $focal->institutions()->attach($institution->id, ['is_primary' => true]);

    Livewire::actingAs($focal)->test('learning.materials')
        ->set('courseId', (string) $course->id)
        ->set('titleText', 'Module 1')
        ->set('description', 'Introduction')
        ->set('upload', UploadedFile::fake()->create('module.pdf', 128, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $material = CourseMaterial::query()->where('title', 'Module 1')->firstOrFail();
    expect($material->course_id)->toBe($course->id)
        ->and($material->uploaded_by_user_id)->toBe($focal->id)
        ->and($material->is_published)->toBeTrue();
    Storage::disk('local')->assertExists($material->path);

    Livewire::actingAs($focal)->test('learning.materials')
        ->set('courseId', (string) $otherCourse->id)
        ->set('titleText', 'Unauthorized material')
        ->set('upload', UploadedFile::fake()->create('other.pdf', 128, 'application/pdf'))
        ->call('save')
        ->assertForbidden();
});

test('learner sees and downloads only published material for their confirmed course', function (): void {
    Storage::fake('local');
    $institution = makeMaterialInstitution('MIF', 'Mamburao Integrated Farm');
    $course = makeMaterialCourse($institution);
    $otherCourse = makeMaterialCourse($institution, 'MIF-OTHER');
    $learner = User::factory()->create(['role' => 'scholar', 'account_status' => 'active']);
    $application = EnrollmentApplication::query()->create([
        'reference_no' => 'MAT-APP-0001', 'learner_user_id' => $learner->id,
        'institution_id' => $institution->id, 'course_id' => $course->id,
        'learner_name' => $learner->name, 'application_status' => 'confirmed',
        'record_completeness' => 100, 'confirmed_at' => now(),
    ]);

    Storage::disk('local')->put('course-materials/mif/module.pdf', 'course content');
    $published = CourseMaterial::query()->create([
        'course_id' => $course->id, 'uploaded_by_user_id' => User::factory()->create(['role' => 'focal_person', 'account_status' => 'active'])->id,
        'title' => 'Published module', 'original_name' => 'module.pdf', 'path' => 'course-materials/mif/module.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 14, 'is_published' => true,
    ]);
    $hidden = CourseMaterial::query()->create([
        'course_id' => $course->id, 'uploaded_by_user_id' => $published->uploaded_by_user_id,
        'title' => 'Hidden module', 'original_name' => 'hidden.pdf', 'path' => 'course-materials/mif/hidden.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 10, 'is_published' => false,
    ]);
    $other = CourseMaterial::query()->create([
        'course_id' => $otherCourse->id, 'uploaded_by_user_id' => $published->uploaded_by_user_id,
        'title' => 'Other course module', 'original_name' => 'other.pdf', 'path' => 'course-materials/mif/other.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 10, 'is_published' => true,
    ]);

    $this->actingAs($learner)->get(route('learning.my-materials'))->assertOk()->assertSee('Published module')->assertDontSee('Hidden module')->assertDontSee('Other course module');
    $this->actingAs($learner)->get(route('course-materials.download', $published))->assertOk()->assertHeader('cache-control', 'max-age=0, no-store, private');
    $this->actingAs($learner)->get(route('course-materials.download', $hidden))->assertForbidden();
    $this->actingAs($learner)->get(route('course-materials.download', $other))->assertForbidden();
});

test('registrar and applicant cannot manage course learning materials', function (): void {
    $institution = makeMaterialInstitution('MIF', 'Mamburao Integrated Farm');
    $registrar = User::factory()->create(['role' => 'registrar', 'account_status' => 'active']);
    $registrar->institutions()->attach($institution->id, ['is_primary' => true]);
    $applicant = User::factory()->create(['role' => 'applicant', 'account_status' => 'active']);

    $this->actingAs($registrar)->get(route('learning.materials'))->assertForbidden();
    $this->actingAs($applicant)->get(route('learning.materials'))->assertForbidden();
});
