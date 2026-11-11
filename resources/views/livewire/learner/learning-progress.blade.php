<?php

use App\Models\EnrollmentApplication;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Learning Progress');

$application = computed(fn () => EnrollmentApplication::query()
    ->with(['course', 'trainingBatch', 'institution', 'statusHistories'])
    ->where('learner_user_id', auth()->id())
    ->latest()
    ->first());
?>

@php
    $record = $this->application;
    $statusHistory = $record?->statusHistories?->sortBy('created_at')->values() ?? collect();
    $guidance = match ($record?->application_status) {
        'draft' => ['title' => 'Continue your application', 'body' => 'Complete the required fields and documents, then submit your registration.', 'action' => true],
        'submitted', 'under_review' => ['title' => 'Your application is being reviewed', 'body' => 'Your submission is in the Registrar review queue. Check this page for updates or requests for revision.', 'action' => false],
        'returned_for_revision' => ['title' => 'Action required: revise your application', 'body' => $record?->remarks ?: 'Review any rejected documents, update the requested information, and resubmit your application.', 'action' => true],
        'verified' => ['title' => 'Requirements verified', 'body' => 'Your submitted requirements have been verified. Wait for enrollment confirmation and Scholar ID issuance.', 'action' => false],
        'confirmed' => ['title' => 'Enrollment confirmed', 'body' => 'Your enrollment is confirmed. Check the other menu pages for your competency progress, attendance, and certificate status.', 'action' => false],
        default => ['title' => 'Start your online application', 'body' => 'Complete your learner profile and upload the required documents to submit your application online.', 'action' => true],
    };
@endphp

<div class="mx-auto max-w-6xl space-y-6 pb-10">
    <section class="rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-900 p-6 text-white shadow-xl sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-300">Student / Scholar</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight">Learning Progress</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-blue-100">Track your registration, approval, batch assignment, and training preparation.</p>
    </section>

    @if ($record)
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">Enrollment status</p><p class="mt-3 text-xl font-black text-slate-900">{{ str($record->application_status)->headline() }}</p></article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">Requirements</p><p class="mt-3 text-xl font-black text-slate-900">{{ max(0, min(100, (int) $record->record_completeness)) }}%</p><div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: {{ max(0, min(100, (int) $record->record_completeness)) }}%"></div></div></article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">Training batch</p><p class="mt-3 text-lg font-black text-slate-900">{{ $record->trainingBatch?->name ?? 'Not assigned' }}</p></article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">Scholar ID</p><p class="mt-3 break-all text-lg font-black text-slate-900">{{ $record->scholar_id ?? 'Pending' }}</p></article>
        </section>

        <section class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(260px,0.75fr)]">
            <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
                <h2 class="text-xl font-black text-slate-900">Application progress</h2>
                <p class="mt-1 text-sm text-slate-500">Reference number: <span class="font-semibold text-slate-700">{{ $record->reference_no }}</span></p>
                <div class="mt-6 space-y-0">
                    @forelse ($statusHistory as $history)
                        <div wire:key="learning-history-{{ $history->id }}" class="relative flex gap-3 pb-5 last:pb-0">
                            @if (! $loop->last)<span class="absolute left-[9px] top-5 h-full w-px bg-slate-200"></span>@endif
                            <span class="relative mt-1 h-5 w-5 shrink-0 rounded-full border-4 border-blue-100 bg-blue-600"></span>
                            <div><p class="font-bold text-slate-800">{{ str($history->to_status)->headline() }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $history->created_at?->format('M d, Y · h:i A') }}</p>@if (filled($history->reason))<p class="mt-2 text-sm text-slate-600">{{ $history->reason }}</p>@endif</div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No status history has been recorded yet.</p>
                    @endforelse
                </div>
            </article>

            <aside class="rounded-3xl border border-blue-100 bg-blue-50 p-6 sm:p-7">
                <p class="text-xs font-bold uppercase tracking-wider text-blue-700">Your next step</p>
                <h2 class="mt-2 text-lg font-black text-slate-900">{{ $guidance['title'] }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-700">{{ $guidance['body'] }}</p>
                @if ($guidance['action'])<a href="{{ route('learner.registration') }}" wire:navigate class="mt-5 inline-flex rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800">{{ $record->application_status === 'returned_for_revision' ? 'Review and resubmit' : 'Continue registration' }}</a>@endif
            </aside>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <h2 class="text-xl font-black text-slate-900">Course information</h2>
            <dl class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4"><div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">Qualification</dt><dd class="mt-1 font-bold text-slate-800">{{ $record->course?->name ?? 'Training course pending' }}</dd></div><div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">Institution</dt><dd class="mt-1 font-bold text-slate-800">{{ $record->institution?->name ?? 'Not assigned' }}</dd></div><div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">Training start date</dt><dd class="mt-1 font-bold text-slate-800">{{ $record->trainingBatch?->starts_at?->format('F d, Y') ?? 'To be announced' }}</dd></div><div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">Training batch</dt><dd class="mt-1 font-bold text-slate-800">{{ $record->trainingBatch?->name ?? 'Not assigned' }}</dd></div></dl>
        </section>
    @else
        <section class="rounded-3xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm"><h2 class="text-xl font-black text-slate-900">No learner application yet</h2><p class="mt-2 text-sm text-slate-500">Complete your registration first to see your learning progress.</p><a href="{{ route('learner.registration') }}" wire:navigate class="mt-5 inline-flex rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-bold text-white">Complete registration</a></section>
    @endif
</div>
