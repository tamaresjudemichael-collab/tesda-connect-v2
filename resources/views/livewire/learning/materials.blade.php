<?php

use App\Models\ActivityLog;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Institution;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;
use function Livewire\Volt\{computed, layout, mount, state, title, uses};

uses([WithFileUploads::class]);
layout('layouts::app');
title('Course Learning Materials');

state([
    'courseId' => '', 'titleText' => '', 'description' => '', 'upload' => null,
    'search' => '', 'showUnpublished' => false,
]);

mount(function (): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('administrator', 'focal_person'), 403);
    $this->courseId = (string) (Course::query()->whereIn('institution_id', InstitutionScope::idsFor($user))->where('is_active', true)->orderBy('name')->value('id') ?? '');
});

$courses = computed(fn () => Course::query()
    ->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()))
    ->where('is_active', true)->orderBy('name')->get());

$materials = computed(function () {
    $user = auth()->user();
    $courseIds = Course::query()->whereIn('institution_id', InstitutionScope::idsFor($user))->select('id');
    return CourseMaterial::query()->with(['course.institution', 'uploadedBy'])
        ->whereIn('course_id', $courseIds)
        ->when(! $this->showUnpublished, fn ($q) => $q->where('is_published', true))
        ->when(trim((string) $this->search) !== '', function ($q): void {
            $term = '%'.trim((string) $this->search).'%';
            $q->where(fn ($inner) => $inner->where('title', 'like', $term)->orWhere('original_name', 'like', $term));
        })->latest()->paginate(15);
});

$save = function (): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('administrator', 'focal_person'), 403);
    $validated = $this->validate([
        'courseId' => ['required', 'integer', Rule::exists('courses', 'id')->where('is_active', true)],
        'titleText' => ['required', 'string', 'max:180'],
        'description' => ['nullable', 'string', 'max:3000'],
        'upload' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,jpg,jpeg,png,zip'],
    ]);
    $course = Course::query()->findOrFail((int) $validated['courseId']);

abort_unless(
    InstitutionScope::idsFor($user)->contains(
        (int) $course->institution_id
    ),
    403
);
    $file = $validated['upload'];
    $path = $file->store('course-materials/'.$course->institution_id.'/'.$course->id, 'local');

    $material = CourseMaterial::query()->create([
        'course_id' => $course->id,
        'uploaded_by_user_id' => $user->id,
        'title' => trim($validated['titleText']),
        'description' => filled($validated['description'] ?? null) ? trim($validated['description']) : null,
        'original_name' => $file->getClientOriginalName(),
        'path' => $path,
        'mime_type' => $file->getMimeType(),
        'size_bytes' => (int) $file->getSize(),
        'is_published' => true,
    ]);
    ActivityLogger::record('course_material_uploaded', 'Uploaded learning material '.$material->title, (int) $course->institution_id, [
        'course_material_id' => $material->id, 'course_id' => $course->id, 'size_bytes' => $material->size_bytes,
    ]);
    $this->reset('titleText', 'description', 'upload');
    unset($this->materials);
    Flux::toast(variant: 'success', text: 'Learning material uploaded and published.');
};

$togglePublish = function (int $id): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('administrator', 'focal_person'), 403);
    $material = CourseMaterial::query()->whereHas('course', fn ($q) => $q->whereIn('institution_id', InstitutionScope::idsFor($user)))->findOrFail($id);
    $material->update(['is_published' => ! $material->is_published]);
    ActivityLogger::record($material->is_published ? 'course_material_published' : 'course_material_unpublished', ($material->is_published ? 'Published' : 'Unpublished').' learning material '.$material->title, (int) $material->course->institution_id, ['course_material_id' => $material->id, 'course_id' => $material->course_id]);
    unset($this->materials);
    Flux::toast(variant: 'success', text: $material->is_published ? 'Material is visible to eligible learners.' : 'Material hidden from learners.');
};

$deleteMaterial = function (int $id): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('administrator', 'focal_person'), 403);
    $material = CourseMaterial::query()->whereHas('course', fn ($q) => $q->whereIn('institution_id', InstitutionScope::idsFor($user)))->findOrFail($id);
    $courseId = $material->course_id;
    $institutionId = (int) $material->course->institution_id;
    Storage::disk('local')->delete($material->path);
    $material->delete();
    ActivityLogger::record('course_material_deleted', 'Deleted learning material '.$material->title, $institutionId, ['course_material_id' => $id, 'course_id' => $courseId]);
    unset($this->materials);
    Flux::toast(variant: 'success', text: 'Learning material deleted.');
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <header class="space-y-2">
        <p class="text-sm font-semibold text-cyan-700">LMS · Assigned institutions only</p>
        <h1 class="text-3xl font-black text-slate-900">Course Learning Materials</h1>
        <p class="max-w-3xl text-sm text-slate-600">Upload course resources for confirmed learners. Files are stored privately and delivered only after institution and enrollment authorization checks.</p>
    </header>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 class="text-lg font-bold text-slate-900">Publish a resource</h2>
        <form wire:submit="save" class="mt-4 grid gap-4 md:grid-cols-2">
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Course</label><select wire:model="courseId" required class="w-full rounded-lg border-slate-300 text-sm"><option value="">Select course</option>@foreach($this->courses as $course)<option value="{{ $course->id }}">{{ $course->code }} — {{ $course->name }}</option>@endforeach</select>@error('courseId')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
            <flux:input wire:model="titleText" label="Resource title" required maxlength="180" placeholder="Module 1: Introduction" />
            <div class="md:col-span-2"><flux:textarea wire:model="description" label="Description / instructions" rows="2" maxlength="3000" /></div>
            <div class="md:col-span-2"><label class="mb-1 block text-sm font-medium text-slate-700">File (PDF, Office document, text, image, or ZIP; max 20 MB)</label><input type="file" wire:model="upload" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.jpg,.jpeg,.png,.zip" class="block w-full text-sm text-slate-700"><div wire:loading wire:target="upload" class="mt-1 text-sm text-slate-500">Uploading…</div>@error('upload')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
            <div class="md:col-span-2"><flux:button type="submit" variant="primary" icon="cloud-arrow-up">Upload and publish</flux:button></div>
        </form>
    </section>

    <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><h2 class="text-lg font-bold text-slate-900">Resource library</h2><div class="flex flex-wrap items-center gap-3"><input wire:model.live.debounce.300ms="search" placeholder="Search title or filename" class="rounded-lg border-slate-300 text-sm"><label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="showUnpublished"> Include hidden</label></div></div>
        <div class="divide-y divide-slate-100">
            @forelse($this->materials as $material)
                <article wire:key="course-material-{{ $material->id }}" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0"><p class="font-semibold text-slate-900">{{ $material->title }}</p><p class="mt-1 text-sm text-slate-600">{{ $material->course->code }} · {{ $material->course->name }} · {{ $material->original_name }}</p>@if($material->description)<p class="mt-1 whitespace-pre-line text-sm text-slate-500">{{ $material->description }}</p>@endif<p class="mt-1 text-xs text-slate-400">{{ number_format($material->size_bytes / 1024, 0) }} KB · {{ $material->created_at->format('M d, Y') }} · {{ $material->is_published ? 'Published' : 'Hidden' }}</p></div>
                    <div class="flex shrink-0 flex-wrap gap-2"><a href="{{ route('course-materials.download', $material) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">Download</a><flux:button size="sm" variant="ghost" wire:click="togglePublish({{ $material->id }})">{{ $material->is_published ? 'Hide' : 'Publish' }}</flux:button><flux:button size="sm" variant="danger" wire:click="deleteMaterial({{ $material->id }})" wire:confirm="Permanently delete this course material?">Delete</flux:button></div>
                </article>
            @empty
                <p class="py-8 text-center text-sm text-slate-500">No learning materials yet. Upload a resource to make it available to eligible learners.</p>
            @endforelse
        </div>
        <div>{{ $this->materials->links() }}</div>
    </section>
</div>
