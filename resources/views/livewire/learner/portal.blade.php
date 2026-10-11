<?php

use App\Models\Announcement;
use App\Models\EnrollmentApplication;
use App\Models\LearnerDocument;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('My Learning Portal');

$application = computed(fn () => EnrollmentApplication::query()
    ->with(['course', 'trainingBatch', 'institution'])
    ->where('learner_user_id', auth()->id())
    ->latest()
    ->first());

$announcements = computed(fn () => Announcement::query()
    ->whereNotNull('published_at')
    ->where(fn ($query) => $query->where('audience', 'all')->orWhere('audience', 'scholar'))
    ->latest('published_at')
    ->take(4)
    ->get());

$documents = computed(fn () => LearnerDocument::query()
    ->where('user_id', auth()->id())
    ->latest()
    ->get());
?>

@php($record = $this->application)

<div class="mx-auto max-w-5xl space-y-6">
    <div>
        <p class="text-sm font-semibold text-cyan-600">Learner workspace</p>
        <flux:heading size="xl" class="mt-1 text-3xl font-black">My Learning Portal</flux:heading>
        <flux:text class="mt-1">Your learner record, training schedule, and task completion updates.</flux:text>
    </div>

    @if ($record)
        <div class="grid gap-6 lg:grid-cols-[1.25fr_.75fr]">
            <flux:card class="bg-white p-6 shadow-sm">
                <p class="text-xs font-bold tracking-wide text-cyan-600">{{ $record->reference_no }}</p>
                <flux:heading size="lg" class="mt-2">{{ $record->course?->name ?? 'Training course pending' }}</flux:heading>
                <flux:text class="mt-2">{{ $record->institution?->name }}</flux:text>
                <div class="mt-6 rounded-xl border border-slate-100 bg-slate-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Enrollment status</p>
                    <p class="mt-2 text-lg font-bold text-[#102f68]">{{ str($record->application_status)->headline() }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $record->remarks ?: 'Your application record is being kept up to date by the training team.' }}</p>
                </div>
                <div class="mt-6">
                    <div class="flex justify-between text-sm"><span class="font-semibold text-slate-700">Registration requirements</span><span class="font-bold text-emerald-600">{{ $record->record_completeness }}% complete</span></div>
                    <div class="mt-2 h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $record->record_completeness }}%"></div></div>
                </div>
            </flux:card>

            <flux:card class="bg-white p-6 shadow-sm">
                <flux:heading size="lg">Training schedule</flux:heading>
                <div class="mt-5 space-y-4">
                    <div><p class="text-xs text-slate-400">Batch</p><p class="mt-1 font-semibold text-slate-700">{{ $record->trainingBatch?->name ?? 'To be assigned' }}</p></div>
                    <div><p class="text-xs text-slate-400">Starts</p><p class="mt-1 font-semibold text-slate-700">{{ $record->trainingBatch?->starts_at?->format('F d, Y') ?? 'To be announced' }}</p></div>
                    <div><p class="text-xs text-slate-400">Learner ID</p><p class="mt-1 font-semibold text-slate-700">{{ $record->scholar_id ?? 'Issued after confirmation' }}</p></div>
                </div>
            </flux:card>
        </div>

        <flux:card class="bg-white p-6 shadow-sm">
            <flux:heading size="lg">Competency task status</flux:heading>
            <flux:text class="mt-1">This view intentionally shows task completion only—no numerical grades or assessment scores.</flux:text>
            <div class="mt-5 grid gap-3 md:grid-cols-3">
                @foreach ([
                    ['Learner profile and requirements', $record->record_completeness === 100 ? 'Complete' : 'In progress', $record->record_completeness === 100 ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'],
                    ['Orientation preparation', $record->trainingBatch ? 'Scheduled' : 'Waiting for schedule', 'bg-blue-50 text-blue-700'],
                    ['Training competency tasks', $record->trainingBatch?->status === 'in_training' ? 'In progress' : 'Not started', 'bg-slate-100 text-slate-700'],
                ] as [$task, $status, $style])
                    <div class="rounded-xl border border-slate-100 p-4"><p class="font-semibold text-slate-700">{{ $task }}</p><span class="mt-3 inline-block rounded-full px-2.5 py-1 text-xs font-bold {{ $style }}">{{ $status }}</span></div>
                @endforeach
            </div>
        </flux:card>
    @else
        <flux:card class="bg-white p-10 text-center shadow-sm">
            <flux:heading size="lg">No learner application yet</flux:heading>
            <flux:text class="mt-2">Once you submit your registration form, your status and schedule will appear here.</flux:text>
            <flux:button class="mt-5" variant="primary" :href="route('learner.registration')" wire:navigate>Complete registration</flux:button>
        </flux:card>
    @endif

    <flux:card class="bg-white p-6 shadow-sm">
        <flux:heading size="lg">Private document status</flux:heading>
        <flux:text class="mt-1">Only you and authorized TESDA staff can access your submitted files.</flux:text>
        <div class="mt-4 space-y-3">
            @forelse ($this->documents as $document)
                <div class="flex items-center justify-between gap-4 rounded-xl bg-slate-50 p-4">
                    <div class="min-w-0"><p class="truncate font-semibold text-slate-700">{{ $document->original_name }}</p><p class="mt-1 text-xs text-slate-400">{{ str($document->document_type)->headline() }} · {{ number_format($document->size / 1024, 0) }} KB</p></div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold {{ $document->status === 'verified' ? 'bg-emerald-50 text-emerald-700' : ($document->status === 'rejected' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">{{ str($document->status)->headline() }}</span>
                </div>
            @empty
                <p class="py-4 text-sm text-slate-400">No documents have been uploaded yet.</p>
            @endforelse
        </div>
        <a class="mt-4 inline-block text-sm font-semibold text-blue-700" href="{{ route('learner.registration') }}" wire:navigate>Upload or update documents →</a>
    </flux:card>

    <flux:card class="bg-white p-6 shadow-sm">
        <flux:heading size="lg">Announcements</flux:heading>
        <div class="mt-4 space-y-3">
            @forelse ($this->announcements as $announcement)
                <div class="rounded-xl bg-slate-50 p-4"><p class="font-semibold text-slate-700">{{ $announcement->title }}</p><p class="mt-1 text-sm text-slate-500">{{ $announcement->body }}</p><p class="mt-2 text-xs text-slate-400">{{ $announcement->published_at?->diffForHumans() }}</p></div>
            @empty
                <p class="text-sm text-slate-400">No new announcements.</p>
            @endforelse
        </div>
    </flux:card>
</div>
