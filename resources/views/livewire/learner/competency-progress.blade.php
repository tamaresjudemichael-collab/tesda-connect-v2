<?php

use App\Models\CompetencyUnit;
use App\Models\EnrollmentApplication;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Training Competency Progress');

$application = computed(fn () => EnrollmentApplication::query()
    ->with('course')
    ->where('learner_user_id', auth()->id())
    ->latest()
    ->first());

$competencyUnits = computed(function () {
    $record = $this->application;
    if (! $record || $record->application_status !== 'confirmed' || ! $record->course_id) return collect();

    return CompetencyUnit::query()->where('course_id', $record->course_id)
        ->with(['tasks' => fn ($query) => $query->where('chart_type', 'progress')->with(['progressRecords' => fn ($progressQuery) => $progressQuery->where('enrollment_application_id', $record->id)])])
        ->orderBy('sort_order')->get();
});

$summary = computed(function () {
    $tasks = $this->competencyUnits->flatMap(fn ($unit) => $unit->tasks);
    $total = $tasks->count();
    $competent = $tasks->filter(fn ($task) => in_array($task->progressRecords->first()?->status, ['competent', 'submitted_output_correct'], true))->count();
    $assessed = $tasks->filter(fn ($task) => ! in_array($task->progressRecords->first()?->status ?? 'not_assessed', ['not_assessed', null], true))->count();

    return ['total' => $total, 'competent' => $competent, 'assessed' => $assessed, 'remaining' => max(0, $total - $competent), 'percent' => $total > 0 ? (int) round(($competent / $total) * 100) : 0];
});
?>

@php($record = $this->application)
@php($units = $this->competencyUnits)
@php($summary = $this->summary)

<div class="mx-auto max-w-6xl space-y-6 pb-10">
    <section class="rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-900 p-6 text-white shadow-xl sm:p-8"><p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-300">Student / Scholar</p><h1 class="mt-2 text-3xl font-black tracking-tight">Training Competency Progress</h1><p class="mt-2 text-sm text-blue-100">View-only competency task status. Grades and numerical assessment scores are not shown.</p></section>
    @if ($record?->application_status === 'confirmed')
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h2 class="text-xl font-black text-slate-900">{{ $record->course?->name ?? 'Your enrolled course' }}</h2><p class="mt-1 text-sm text-slate-500">Progress for the competency tasks in your enrolled course.</p></div><span class="inline-flex w-fit rounded-full bg-blue-50 px-3 py-1.5 text-sm font-bold text-blue-700">{{ $summary['percent'] }}% competent</span></div>
            <div class="mt-5 grid gap-3 sm:grid-cols-4"><div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-semibold text-slate-500">Total tasks</p><p class="mt-1 text-2xl font-black text-slate-900">{{ $summary['total'] }}</p></div><div class="rounded-xl bg-emerald-50 p-4"><p class="text-xs font-semibold text-emerald-700">Competent</p><p class="mt-1 text-2xl font-black text-emerald-800">{{ $summary['competent'] }}</p></div><div class="rounded-xl bg-amber-50 p-4"><p class="text-xs font-semibold text-amber-700">Assessed</p><p class="mt-1 text-2xl font-black text-amber-800">{{ $summary['assessed'] }}</p></div><div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-semibold text-slate-500">Remaining</p><p class="mt-1 text-2xl font-black text-slate-900">{{ $summary['remaining'] }}</p></div></div>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $summary['percent'] }}%"></div></div>
            <div class="mt-6 space-y-4">@forelse ($units as $unit)<article class="overflow-hidden rounded-2xl border border-slate-200" wire:key="learner-competency-unit-{{ $unit->id }}"><div class="bg-slate-50 px-4 py-3"><h3 class="font-bold text-slate-900">Unit {{ $unit->unit_number }} — {{ $unit->name }}</h3></div><div class="divide-y divide-slate-100">@forelse ($unit->tasks as $task)@php($taskStatus = $task->progressRecords->first()?->status ?? 'not_assessed')@php($taskLabel = in_array($taskStatus, ['competent', 'submitted_output_correct'], true) ? 'Competent' : ($taskStatus === 'not_yet_competent' ? 'Not Yet Competent' : 'Not Assessed'))<div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="learner-competency-task-{{ $task->id }}"><span class="text-sm text-slate-700">{{ $task->name }}</span><span class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold {{ $taskLabel === 'Competent' ? 'bg-emerald-50 text-emerald-700' : ($taskLabel === 'Not Yet Competent' ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-600') }}">{{ $taskLabel }}</span></div>@empty<div class="px-4 py-3 text-sm text-slate-500">No tasks are configured for this unit yet.</div>@endforelse</div></article>@empty<div class="rounded-xl border border-dashed border-slate-200 p-5 text-sm text-slate-500">No competency tasks are configured for this course yet. Ask your Focal Person to check the setup.</div>@endforelse</div>
        </section>
    @else
        <section class="rounded-3xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm"><h2 class="text-xl font-black text-slate-900">Competency progress is not available yet</h2><p class="mt-2 text-sm text-slate-500">This page becomes available after your enrollment is confirmed.</p></section>
    @endif
</div>
