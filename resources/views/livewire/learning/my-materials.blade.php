<?php

use App\Models\CourseMaterial;
use App\Models\EnrollmentApplication;
use function Livewire\Volt\{computed, layout, mount, title};

layout('layouts::app');
title('My Course Materials');

mount(function (): void {
    abort_unless(auth()->user()?->account_status === 'active' && auth()->user()->hasRole('applicant', 'scholar'), 403);
});

$application = computed(fn () => EnrollmentApplication::query()
    ->with(['course', 'institution'])
    ->where('learner_user_id', auth()->id())
    ->where('application_status', 'confirmed')
    ->latest('confirmed_at')->latest('id')->first());

$materials = computed(function () {
    $application = $this->application;
    if (! $application || ! $application->course_id) return collect();

    return CourseMaterial::query()->where('course_id', $application->course_id)
        ->where('is_published', true)->with('course')
        ->latest()->paginate(15);
});
?>

<div class="mx-auto max-w-5xl space-y-6">
    <header class="space-y-2"><p class="text-sm font-semibold text-cyan-700">Learner workspace · LMS</p><h1 class="text-3xl font-black text-slate-900">My Course Materials</h1><p class="text-sm text-slate-600">Access resources published for your confirmed training course. Downloads require an active account and a matching confirmed enrollment.</p></header>
    @if($this->application)
        <section class="rounded-2xl border border-blue-100 bg-blue-50 p-5"><p class="text-sm text-blue-700">Your course</p><h2 class="mt-1 text-xl font-bold text-slate-900">{{ $this->application->course?->name ?? 'Course not assigned' }}</h2><p class="mt-1 text-sm text-slate-600">{{ $this->application->institution?->name }}</p></section>
        <section class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white px-5 shadow-sm">
            @forelse($this->materials as $material)
                <article wire:key="learner-material-{{ $material->id }}" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"><div><h3 class="font-semibold text-slate-900">{{ $material->title }}</h3>@if($material->description)<p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $material->description }}</p>@endif<p class="mt-1 text-xs text-slate-400">{{ $material->original_name }} · {{ number_format($material->size_bytes / 1024, 0) }} KB · Added {{ $material->created_at->format('M d, Y') }}</p></div><a href="{{ route('course-materials.download', $material) }}" class="inline-flex w-fit rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Download resource</a></article>
            @empty
                <p class="py-8 text-center text-sm text-slate-500">No resources have been published for your course yet.</p>
            @endforelse
            <div class="py-4">{{ $this->materials->links() }}</div>
        </section>
    @else
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5"><h2 class="font-bold text-amber-950">Course materials are not available yet</h2><p class="mt-1 text-sm text-amber-900">Materials appear here after your enrollment is confirmed and a course is assigned. You can still review your application status in the learner dashboard.</p><a href="{{ route('learner.portal') }}" wire:navigate class="mt-3 inline-flex rounded-lg bg-amber-900 px-4 py-2 text-sm font-semibold text-white">Open learner dashboard</a></section>
    @endif
</div>
