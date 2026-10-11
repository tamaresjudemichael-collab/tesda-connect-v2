<?php

use App\Models\ActivityLog;
use App\Models\EnrollmentApplication;
use App\Models\TrainingBatch;
use Flux\Flux;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Dashboard');

$dashboard = computed(function (): array {
    $user = auth()->user();
    $institutionIds = $user->institutions()->pluck('institutions.id');
    $applications = EnrollmentApplication::query()->whereIn('institution_id', $institutionIds);
    $total = (clone $applications)->count();
    $complete = (clone $applications)->where('record_completeness', 100)->count();

    return [
        'role' => $user->role,
        'total_trainees' => $total,
        'pending_intakes' => (clone $applications)->whereIn('application_status', ['submitted', 'under_review', 'returned_for_revision'])->count(),
        'incomplete_records' => (clone $applications)->where('record_completeness', '<', 100)->count(),
        'upcoming_batches' => TrainingBatch::query()->whereIn('institution_id', $institutionIds)->where('status', 'upcoming')->count(),
        'readiness' => $total === 0 ? 0 : (int) round(($complete / $total) * 100),
        'batches' => TrainingBatch::query()->with('course')->whereIn('institution_id', $institutionIds)->orderBy('starts_at')->take(6)->get(),
        'activities' => ActivityLog::query()->whereIn('institution_id', $institutionIds)->latest()->take(6)->get(),
    ];
});

$refresh = function (): void {
    unset($this->dashboard);
    Flux::toast(variant: 'success', text: 'Dashboard data refreshed.');
};
?>

@php($data = $this->dashboard)

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-cyan-600">TESDAConnect workspace</p>
            <flux:heading size="xl" class="mt-1 text-3xl font-black tracking-tight">Good day, {{ auth()->user()->name }}.</flux:heading>
            <flux:text class="mt-1 text-base text-slate-500">Here is your live overview of TESDA training operations.</flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button variant="ghost" icon="arrow-path" wire:click="refresh">Refresh data</flux:button>
            @if (auth()->user()->hasRole('administrator', 'registrar'))
                <flux:button variant="primary" :href="route('enrollments.index')" wire:navigate icon="clipboard-document-list">View records</flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ([
            ['Total Trainees', $data['total_trainees'], 'Active learner records', 'border-l-[#173b78]', '♟'],
            ['Pending Intake Forms', $data['pending_intakes'], 'Needs staff review', 'border-l-amber-500', '▤'],
            ['Incomplete Records', $data['incomplete_records'], 'Documents or fields missing', 'border-l-rose-500', '⚠'],
            ['Upcoming Batches', $data['upcoming_batches'], 'Ready for coordination', 'border-l-emerald-500', '□'],
            ['Report Readiness', $data['readiness'].'%', 'Based on complete records', 'border-l-cyan-500', '◴'],
        ] as [$label, $value, $detail, $border, $icon])
            <flux:card class="border-l-4 {{ $border }} bg-white p-5 shadow-sm">
                <span class="grid size-10 place-items-center rounded-xl bg-slate-50 text-lg text-slate-500">{{ $icon }}</span>
                <p class="mt-4 text-4xl font-black tracking-tight text-[#102f68]">{{ $value }}</p>
                <p class="mt-1 text-sm font-semibold text-slate-700">{{ $label }}</p>
                <p class="mt-2 text-xs text-slate-400">{{ $detail }}</p>
            </flux:card>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.8fr)_minmax(20rem,.85fr)]">
        <flux:card class="bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between"><div><flux:heading size="lg">Trainee enrollment per batch</flux:heading><flux:text class="mt-1">Current records assigned to each training batch</flux:text></div><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">Live data</span></div>
            <div class="mt-8 flex h-64 items-end gap-3 border-b border-slate-100 px-2 pb-7">
                @forelse ($data['batches'] as $batch)
                    @php($enrolled = $batch->applications()->count())
                    @php($height = max(12, min(100, $batch->capacity ? ($enrolled / $batch->capacity) * 100 : 0)))
                    <div class="flex min-w-0 flex-1 flex-col items-center gap-2"><span class="text-sm font-bold text-slate-600">{{ $enrolled }}</span><div class="w-full max-w-16 rounded-t-xl bg-gradient-to-t from-[#173b78] to-cyan-500 transition-all" style="height: {{ $height }}%"></div><span class="truncate text-center text-xs text-slate-500">{{ $batch->name }}</span></div>
                @empty
                    <div class="grid h-full w-full place-items-center text-sm text-slate-400">No batches have been created yet.</div>
                @endforelse
            </div>
        </flux:card>

        <flux:card class="bg-white p-6 shadow-sm">
            <flux:heading size="lg">Record completeness</flux:heading>
            <div class="mt-7 grid place-items-center"><div class="grid size-48 place-items-center rounded-full" style="background: conic-gradient(#22c55e {{ $data['readiness'] }}%, #fee2e2 0)"><div class="grid size-32 place-items-center rounded-full bg-white text-center"><span class="text-4xl font-black text-[#102f68]">{{ $data['readiness'] }}%</span><span class="-mt-5 text-xs text-slate-400">complete</span></div></div></div>
            <div class="mt-7 grid grid-cols-2 gap-3 text-sm"><div class="rounded-xl bg-emerald-50 p-3"><p class="font-bold text-emerald-700">Complete</p><p class="mt-1 text-xs text-emerald-600">Ready for reports</p></div><div class="rounded-xl bg-rose-50 p-3"><p class="font-bold text-rose-700">Action needed</p><p class="mt-1 text-xs text-rose-600">Complete missing data</p></div></div>
        </flux:card>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_1fr]">
        <flux:card class="bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between"><flux:heading size="lg">Recent activity</flux:heading><a href="{{ auth()->user()->hasRole('administrator') ? route('system.activity') : route('reports.index') }}" class="text-sm font-semibold text-blue-700" wire:navigate>View all</a></div>
            <div class="mt-4 divide-y divide-slate-100">
                @forelse ($data['activities'] as $activity)
                    <div class="flex gap-3 py-4"><span class="mt-0.5 grid size-8 place-items-center rounded-full bg-blue-50 text-sm">●</span><div><p class="text-sm font-semibold text-slate-700">{{ $activity->description }}</p><p class="mt-1 text-xs text-slate-400">{{ $activity->created_at->diffForHumans() }}</p></div></div>
                @empty
                    <p class="py-8 text-center text-sm text-slate-400">No activity has been logged yet.</p>
                @endforelse
            </div>
        </flux:card>

        <flux:card class="bg-white p-6 shadow-sm">
            <flux:heading size="lg">Quick actions</flux:heading>
            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @if (auth()->user()->hasRole('administrator', 'registrar'))
                    <flux:button variant="primary" :href="route('enrollments.index')" wire:navigate icon="user-plus">Review intake</flux:button>
                @endif
                @if (auth()->user()->hasRole('administrator', 'registrar', 'focal_person'))
                    <flux:button variant="filled" :href="route('schedules.index')" wire:navigate icon="calendar-days">Manage schedules</flux:button>
                    <flux:button variant="filled" :href="route('reports.index')" wire:navigate icon="chart-bar">Open reports</flux:button>
                @endif
                @if (auth()->user()->hasRole('scholar'))
                    <flux:button variant="primary" :href="route('learner.portal')" wire:navigate icon="academic-cap">My learning portal</flux:button>
                @endif
            </div>
            <div class="mt-8 border-t border-slate-100 pt-5"><p class="text-sm font-bold text-slate-700">Batch status overview</p><div class="mt-4 space-y-4">@forelse ($data['batches'] as $batch)<div><div class="mb-1 flex justify-between text-xs"><span class="font-medium text-slate-600">{{ $batch->name }}</span><span class="font-bold text-slate-500">{{ $batch->status === 'in_training' ? 'In training' : 'Upcoming' }}</span></div><div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $batch->status === 'in_training' ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $batch->status === 'in_training' ? '72' : '35' }}%"></div></div></div>@empty <p class="text-sm text-slate-400">No batch data yet.</p>@endforelse</div></div>
        </flux:card>
    </div>
</div>
