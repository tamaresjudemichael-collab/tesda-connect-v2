<?php

use App\Models\Institution;
use App\Models\TrainingBatch;
use Illuminate\Support\Carbon;
use function Livewire\Volt\{computed, layout, mount, state, title};

layout('layouts::app');
title('Training Calendar');

state([
    'viewMode' => 'month',
    'currentDate' => '',
    'selectedDate' => '',
    'institutionFilter' => 'all',
]);

mount(function (): void {
    $this->currentDate = today()->toDateString();
    $this->selectedDate = today()->toDateString();
});

$institutions = computed(fn () => Institution::query()->orderBy('name')->get());

$calendarBatches = computed(function () {
    $anchor = Carbon::parse($this->currentDate ?: today());
    if ($this->viewMode === 'week') {
        $rangeStart = $anchor->copy()->startOfWeek();
        $rangeEnd = $anchor->copy()->endOfWeek();
    } else {
        $rangeStart = $anchor->copy()->startOfMonth()->startOfWeek();
        $rangeEnd = $anchor->copy()->endOfMonth()->endOfWeek();
    }

    return TrainingBatch::query()
        ->with(['institution', 'course'])
        ->withCount(['applications as confirmed_enrollees_count' => fn ($query) => $query->where('application_status', 'confirmed')])
        ->whereNotNull('starts_at')
        ->whereNotNull('ends_at')
        ->whereDate('starts_at', '<=', $rangeEnd->toDateString())
        ->whereDate('ends_at', '>=', $rangeStart->toDateString())
        ->whereNotIn('status', ['cancelled'])
        ->when($this->institutionFilter !== 'all', fn ($query) => $query->where('institution_id', $this->institutionFilter))
        ->orderBy('starts_at')
        ->orderBy('name')
        ->get();
});

$overlapAlerts = computed(function () {
    $batches = $this->calendarBatches->values();
    $alerts = [];
    $count = $batches->count();

    for ($i = 0; $i < $count; $i++) {
        $first = $batches[$i];
        for ($j = $i + 1; $j < $count; $j++) {
            $second = $batches[$j];
            if ((int) $first->institution_id !== (int) $second->institution_id) {
                continue;
            }

            if ($first->starts_at->lte($second->ends_at) && $second->starts_at->lte($first->ends_at)) {
                $alerts[] = [
                    'institution' => $first->institution?->name ?? 'Unknown institution',
                    'first' => $first,
                    'second' => $second,
                    'start' => $first->starts_at->gt($second->starts_at) ? $first->starts_at : $second->starts_at,
                    'end' => $first->ends_at->lt($second->ends_at) ? $first->ends_at : $second->ends_at,
                ];
            }
        }
    }

    return collect($alerts)->take(12);
});

$previousPeriod = function (): void {
    $anchor = Carbon::parse($this->currentDate ?: today());
    $this->currentDate = ($this->viewMode === 'week' ? $anchor->subWeek() : $anchor->subMonthNoOverflow())->toDateString();
    $this->selectedDate = $this->currentDate;
    unset($this->calendarBatches, $this->overlapAlerts);
};

$nextPeriod = function (): void {
    $anchor = Carbon::parse($this->currentDate ?: today());
    $this->currentDate = ($this->viewMode === 'week' ? $anchor->addWeek() : $anchor->addMonthNoOverflow())->toDateString();
    $this->selectedDate = $this->currentDate;
    unset($this->calendarBatches, $this->overlapAlerts);
};

$goToday = function (): void {
    $this->currentDate = today()->toDateString();
    $this->selectedDate = today()->toDateString();
    unset($this->calendarBatches, $this->overlapAlerts);
};

$selectDate = function (string $date): void {
    $this->selectedDate = Carbon::parse($date)->toDateString();
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div><p class="text-sm font-semibold text-cyan-700">Scheduling management</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Training Calendar</flux:heading><flux:text class="mt-1">Monthly and weekly schedule view for training batches, enrollee counts, and same-institution date overlaps.</flux:text></div>
        <div class="flex flex-wrap gap-2"><flux:button href="{{ route('schedules.index') }}" wire:navigate variant="filled" icon="calendar-days">Create / edit schedules</flux:button><flux:button href="{{ route('system.duration-compliance') }}" wire:navigate variant="ghost" icon="clipboard-document-check">Duration compliance</flux:button></div>
    </div>

    <flux:card class="p-4 shadow-sm sm:p-5">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div class="flex flex-wrap items-center gap-2"><flux:button variant="ghost" icon="chevron-left" wire:click="previousPeriod" aria-label="Previous period"/><flux:button variant="ghost" icon="chevron-right" wire:click="nextPeriod" aria-label="Next period"/><flux:button variant="filled" wire:click="goToday">Today</flux:button><flux:heading size="lg" class="ml-1">{{ $viewMode === 'week' ? \Illuminate\Support\Carbon::parse($currentDate)->startOfWeek()->format('M d') . ' – ' . \Illuminate\Support\Carbon::parse($currentDate)->endOfWeek()->format('M d, Y') : \Illuminate\Support\Carbon::parse($currentDate)->format('F Y') }}</flux:heading></div>
            <div class="flex flex-wrap items-center gap-3"><flux:select wire:model.live="institutionFilter" label="Institution" class="min-w-48"><flux:select.option value="all">All institutions</flux:select.option>@foreach ($this->institutions as $institution)<flux:select.option :value="$institution->id">{{ $institution->code }} — {{ $institution->name }}</flux:select.option>@endforeach</flux:select><div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1"><button type="button" wire:click="$set('viewMode','month')" class="rounded-lg px-3 py-2 text-sm font-semibold {{ $viewMode === 'month' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500' }}">Month</button><button type="button" wire:click="$set('viewMode','week')" class="rounded-lg px-3 py-2 text-sm font-semibold {{ $viewMode === 'week' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500' }}">Week</button></div></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-4 text-xs font-semibold text-slate-600"><span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-blue-600"></span>BST</span><span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-emerald-600"></span>MIF</span><span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-slate-400"></span>Other institution</span><span class="text-slate-400">A batch spans each day from start date through end date.</span></div>

        @php
            $anchorDate = \Illuminate\Support\Carbon::parse($currentDate ?: today());
            if ($viewMode === 'week') {
                $gridStart = $anchorDate->copy()->startOfWeek();
                $gridEnd = $anchorDate->copy()->endOfWeek();
            } else {
                $gridStart = $anchorDate->copy()->startOfMonth()->startOfWeek();
                $gridEnd = $anchorDate->copy()->endOfMonth()->endOfWeek();
            }
            $calendarDays = \Carbon\CarbonPeriod::create($gridStart, $gridEnd);
            $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        @endphp

        <div class="mt-4 grid grid-cols-7 border-l border-t border-slate-200">
            @foreach ($dayNames as $dayName)<div class="border-b border-r border-slate-200 bg-slate-50 px-2 py-2 text-center text-xs font-bold uppercase tracking-wide text-slate-500">{{ $dayName }}</div>@endforeach
            @foreach ($calendarDays as $day)
                @php
                    $dateString = $day->toDateString();
                    $dayBatches = $this->calendarBatches->filter(fn ($batch) => $batch->starts_at && $batch->ends_at && $day->gte($batch->starts_at) && $day->lte($batch->ends_at));
                    $inMonth = $viewMode === 'week' || $day->month === $anchorDate->month;
                @endphp
                <button type="button" wire:click="selectDate('{{ $dateString }}')" class="min-h-28 border-b border-r border-slate-200 p-1.5 text-left transition sm:min-h-32 sm:p-2 {{ $inMonth ? 'bg-white' : 'bg-slate-50/70 text-slate-400' }} {{ $selectedDate === $dateString ? 'ring-2 ring-inset ring-blue-500 bg-blue-50/50' : 'hover:bg-slate-50' }}">
                    <div class="flex items-center justify-between"><span class="grid size-6 place-items-center rounded-full text-xs font-bold {{ $dateString === today()->toDateString() ? 'bg-blue-700 text-white' : 'text-slate-600' }}">{{ $day->day }}</span>@if ($dayBatches->count())<span class="text-[10px] font-semibold text-slate-400">{{ $dayBatches->count() }}</span>@endif</div>
                    <div class="mt-1 space-y-1">
                        @foreach ($dayBatches->take(3) as $batch)
                            @php($code = strtoupper((string) ($batch->institution?->code ?? '')))
                            <div class="truncate rounded px-1.5 py-1 text-[10px] font-semibold leading-tight text-white sm:text-xs {{ $code === 'BST' ? 'bg-blue-600' : ($code === 'MIF' ? 'bg-emerald-600' : 'bg-slate-500') }}" title="{{ $batch->name }} — {{ $batch->institution?->name }}">{{ $batch->code }}</div>
                        @endforeach
                        @if ($dayBatches->count() > 3)<p class="px-1 text-[10px] font-semibold text-slate-500">+{{ $dayBatches->count() - 3 }} more</p>@endif
                    </div>
                </button>
            @endforeach
        </div>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
        <flux:card class="p-5 shadow-sm sm:p-6">
            <div class="flex items-start justify-between gap-3"><div><flux:heading size="lg">Batches on {{ \Illuminate\Support\Carbon::parse($selectedDate ?: today())->format('M d, Y') }}</flux:heading><flux:text class="mt-1">Batch details and confirmed trainee counts.</flux:text></div><span class="rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-700">{{ $this->calendarBatches->filter(fn ($batch) => $batch->starts_at && $batch->ends_at && \Illuminate\Support\Carbon::parse($selectedDate)->betweenIncluded($batch->starts_at, $batch->ends_at))->count() }} batch(es)</span></div>
            <div class="mt-4 space-y-3">
                @php($selectedBatches = $this->calendarBatches->filter(fn ($batch) => $batch->starts_at && $batch->ends_at && \Illuminate\Support\Carbon::parse($selectedDate)->betweenIncluded($batch->starts_at, $batch->ends_at)))
                @forelse ($selectedBatches as $batch)
                    <div class="rounded-xl border border-slate-200 p-4" wire:key="calendar-batch-{{ $batch->id }}"><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-wide text-cyan-700">{{ $batch->code }} · {{ $batch->institution?->code }}</p><p class="mt-1 font-semibold text-slate-900">{{ $batch->name }}</p><p class="mt-1 text-sm text-slate-500">{{ $batch->course?->name }}</p></div><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ str($batch->status)->headline() }}</span></div><div class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><p class="text-xs text-slate-400">Dates</p><p class="mt-1 font-semibold">{{ $batch->starts_at->format('M d') }} – {{ $batch->ends_at->format('M d, Y') }}</p></div><div><p class="text-xs text-slate-400">Confirmed trainees</p><p class="mt-1 font-semibold">{{ $batch->confirmed_enrollees_count }} / {{ $batch->capacity }}</p></div><div><p class="text-xs text-slate-400">Scheduled / target hours</p><p class="mt-1 font-semibold">{{ $batch->scheduled_hours !== null ? $batch->scheduled_hours.' hours' : 'Not configured' }} / {{ $batch->course?->training_hours !== null ? $batch->course->training_hours.' hours' : 'Not configured' }}</p></div></div></div>
                @empty <div class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">No scheduled batch on this date.</div> @endforelse
            </div>
        </flux:card>

        <flux:card class="p-5 shadow-sm sm:p-6">
            <div><flux:heading size="lg">Overlap alerts</flux:heading><flux:text class="mt-1">Two or more batches at the same institution with intersecting dates.</flux:text></div>
            <div class="mt-4 space-y-3">
                @forelse ($this->overlapAlerts as $alert)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4" wire:key="overlap-{{ $alert['first']->id }}-{{ $alert['second']->id }}"><p class="text-xs font-bold uppercase tracking-wide text-amber-800">Potential schedule overlap</p><p class="mt-1 font-semibold text-slate-900">{{ $alert['institution'] }}</p><p class="mt-1 text-sm text-slate-700">{{ $alert['first']->code }} — {{ $alert['first']->name }}</p><p class="text-sm text-slate-700">{{ $alert['second']->code }} — {{ $alert['second']->name }}</p><p class="mt-2 text-xs text-amber-800">Overlap: {{ $alert['start']->format('M d, Y') }} – {{ $alert['end']->format('M d, Y') }}</p></div>
                @empty <div class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">No overlaps detected in this view.</div> @endforelse
            </div>
        </flux:card>
    </div>
</div>
