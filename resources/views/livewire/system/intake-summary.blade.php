<?php

use App\Models\EnrollmentApplication;
use App\Models\IntakePeriod;
use App\Models\Institution;
use App\Models\Course;
use App\Support\ActivityLogger;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, mount, state, title};

layout('layouts::app');
title('Intake Summary');

state([
    'formOpen' => false,
    'editingId' => null,
    'selectedPeriodId' => '',
    'code' => '',
    'name' => '',
    'starts_at' => '',
    'ends_at' => '',
    'quota' => 100,
    'notes' => '',
]);

$actualCountFor = function (IntakePeriod $period): int {
    return EnrollmentApplication::query()
        ->whereNotNull('submitted_at')
        ->where('submitted_at', '>=', $period->starts_at->copy()->startOfDay())
        ->where('submitted_at', '<=', $period->ends_at->copy()->endOfDay())
        ->count();
};

$refreshFullLocks = function () use ($actualCountFor): void {
    foreach (IntakePeriod::query()->where('is_locked', false)->get() as $period) {
        if ($period->quota > 0 && $actualCountFor($period) >= $period->quota) {
            $period->forceFill([
                'is_locked' => true,
                'locked_at' => now(),
                'locked_by_user_id' => auth()->id(),
            ])->save();

            ActivityLogger::record('intake_period_auto_locked', 'Automatically locked intake period '.$period->name.' because its quota was reached.', null, [
                'intake_period_id' => $period->id,
                'quota' => $period->quota,
            ]);
        }
    }
};

mount(function () use ($refreshFullLocks): void {
    $refreshFullLocks();
    if (! filled($this->selectedPeriodId)) {
        $this->selectedPeriodId = (string) (IntakePeriod::query()
            ->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today())
            ->orderByDesc('starts_at')
            ->value('id') ?? IntakePeriod::query()->orderByDesc('starts_at')->value('id') ?? '');
    }
});

$periods = computed(function () use ($actualCountFor) {
    return IntakePeriod::query()->orderByDesc('starts_at')->get()->map(function (IntakePeriod $period) use ($actualCountFor) {
        $actual = $actualCountFor($period);
        $period->setAttribute('actual_count', $actual);
        $period->setAttribute('is_full', $period->quota > 0 && $actual >= $period->quota);
        $period->setAttribute('effective_locked', $period->is_locked || ($period->quota > 0 && $actual >= $period->quota));
        return $period;
    });
});

$selectedPeriod = computed(function () {
    if (filled($this->selectedPeriodId)) {
        return IntakePeriod::query()->find($this->selectedPeriodId);
    }

    return IntakePeriod::query()
        ->whereDate('starts_at', '<=', today())
        ->whereDate('ends_at', '>=', today())
        ->orderByDesc('starts_at')
        ->first() ?? IntakePeriod::query()->orderByDesc('starts_at')->first();
});

$selectedApplications = computed(function () {
    $period = $this->selectedPeriod;
    if (! $period) {
        return collect();
    }

    return EnrollmentApplication::query()
        ->with(['institution', 'course'])
        ->whereNotNull('submitted_at')
        ->where('submitted_at', '>=', $period->starts_at->copy()->startOfDay())
        ->where('submitted_at', '<=', $period->ends_at->copy()->endOfDay())
        ->get();
});

$institutionDistribution = computed(fn () => $this->selectedApplications
    ->groupBy(fn ($application) => $application->institution?->name ?? 'Unknown institution')
    ->map(fn ($items, $name) => ['name' => $name, 'count' => $items->count()])
    ->sortByDesc('count')->values());

$courseDistribution = computed(fn () => $this->selectedApplications
    ->groupBy(fn ($application) => $application->course?->name ?? 'No course assigned')
    ->map(fn ($items, $name) => ['name' => $name, 'count' => $items->count()])
    ->sortByDesc('count')->values());

$resetForm = function (): void {
    $this->reset('editingId', 'code', 'name', 'starts_at', 'ends_at', 'notes');
    $this->quota = 100;
    $this->resetValidation();
};

$openCreate = function (): void {
    $this->resetForm();
    $this->formOpen = true;
};

$edit = function (int $id): void {
    $period = IntakePeriod::findOrFail($id);
    $this->editingId = $period->id;
    $this->selectedPeriodId = (string) $period->id;
    $this->code = $period->code;
    $this->name = $period->name;
    $this->starts_at = $period->starts_at->toDateString();
    $this->ends_at = $period->ends_at->toDateString();
    $this->quota = $period->quota;
    $this->notes = $period->notes ?? '';
    $this->resetValidation();
    $this->formOpen = true;
};

$save = function () use ($actualCountFor, $refreshFullLocks): void {
    $this->code = strtoupper(trim($this->code));
    $validated = $this->validate([
        'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]{2,40}$/', Rule::unique('intake_periods', 'code')->ignore($this->editingId)],
        'name' => ['required', 'string', 'max:120'],
        'starts_at' => ['required', 'date'],
        'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        'quota' => ['required', 'integer', 'min:1', 'max:1000000'],
        'notes' => ['nullable', 'string', 'max:2000'],
    ]);

    $overlap = IntakePeriod::query()
        ->whereDate('starts_at', '<=', $validated['ends_at'])
        ->whereDate('ends_at', '>=', $validated['starts_at'])
        ->when($this->editingId, fn ($query) => $query->where('id', '!=', $this->editingId))
        ->exists();

    if ($overlap) {
        $this->addError('starts_at', 'This period overlaps another intake period. Choose non-overlapping dates.');
        return;
    }

    $wasEditing = filled($this->editingId);
    if ($wasEditing) {
        $period = IntakePeriod::findOrFail($this->editingId);
        $period->update($validated);
    } else {
        $period = IntakePeriod::create($validated + ['created_by_user_id' => auth()->id()]);
    }

    ActivityLogger::record($wasEditing ? 'intake_period_updated' : 'intake_period_created', ($wasEditing ? 'Updated' : 'Created').' intake period '.$period->name.'.', null, [
        'intake_period_id' => $period->id,
        'code' => $period->code,
        'quota' => $period->quota,
    ]);

    $this->selectedPeriodId = (string) $period->id;
    $this->resetForm();
    $this->formOpen = false;
    $refreshFullLocks();
    unset($this->periods, $this->selectedPeriod, $this->selectedApplications, $this->institutionDistribution, $this->courseDistribution);
    Flux::toast(variant: 'success', text: 'Intake period saved.');
};

$toggleLock = function (int $id) use ($actualCountFor): void {
    $period = IntakePeriod::findOrFail($id);
    $actual = $actualCountFor($period);
    $full = $period->quota > 0 && $actual >= $period->quota;

    if ($period->is_locked && $full) {
        Flux::toast(variant: 'warning', text: 'This intake period has reached its quota and cannot be reopened. Create another period or increase the quota first.');
        return;
    }

    $locking = ! $period->is_locked;
    $period->forceFill([
        'is_locked' => $locking,
        'locked_at' => $locking ? now() : null,
        'locked_by_user_id' => $locking ? auth()->id() : null,
    ])->save();

    ActivityLogger::record($locking ? 'intake_period_locked' : 'intake_period_unlocked', ($locking ? 'Locked' : 'Reopened').' intake period '.$period->name.'.', null, [
        'intake_period_id' => $period->id,
        'actual_count' => $actual,
        'quota' => $period->quota,
    ]);
    unset($this->periods, $this->selectedPeriod, $this->selectedApplications);
    Flux::toast(variant: 'success', text: $locking ? 'Intake period locked.' : 'Intake period reopened.');
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-cyan-700">Enrollment monitoring</p>
            <flux:heading size="xl" class="mt-1 text-3xl font-black">Intake Summary</flux:heading>
            <flux:text class="mt-1">Manage intake windows, quotas, and enrollment distribution across BST and MIF.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="openCreate">Create intake period</flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="p-5"><flux:text>Intake periods</flux:text><flux:heading size="xl" class="mt-2">{{ $this->periods->count() }}</flux:heading></flux:card>
        <flux:card class="p-5"><flux:text>Currently active</flux:text><flux:heading size="xl" class="mt-2">{{ $this->periods->filter(fn ($period) => $period->starts_at->lte(today()) && $period->ends_at->gte(today()) && ! $period->effective_locked)->count() }}</flux:heading></flux:card>
        <flux:card class="p-5"><flux:text>Locked / full</flux:text><flux:heading size="xl" class="mt-2">{{ $this->periods->where('effective_locked', true)->count() }}</flux:heading></flux:card>
        <flux:card class="p-5"><flux:text>Total submitted within periods</flux:text><flux:heading size="xl" class="mt-2">{{ $this->periods->sum('actual_count') }}</flux:heading></flux:card>
    </div>

    @if ($formOpen)
        <flux:card class="p-5 shadow-sm sm:p-6">
            <div class="flex items-start justify-between"><div><flux:heading size="lg">{{ $editingId ? 'Edit intake period' : 'Create intake period' }}</flux:heading><flux:text class="mt-1">Periods must not overlap. The quota is compared with applications submitted within the dates.</flux:text></div><flux:button variant="ghost" icon="x-mark" wire:click="$set('formOpen', false)" /></div>
            <form wire:submit="save" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <flux:input wire:model="code" label="Period code" placeholder="FY2026-INTAKE-2" required />
                <flux:input wire:model="name" label="Period name" placeholder="2nd Intake 2026" required />
                <flux:input wire:model="quota" type="number" min="1" label="Enrollment quota" required />
                <flux:input wire:model="starts_at" type="date" label="Start date" required />
                <flux:input wire:model="ends_at" type="date" label="End date" required />
                <flux:input wire:model="notes" label="Notes (optional)" />
                <div class="flex items-end justify-end gap-2 md:col-span-2 xl:col-span-3"><flux:button variant="ghost" type="button" wire:click="resetForm">Reset</flux:button><flux:button variant="primary" type="submit">Save period</flux:button></div>
            </form>
        </flux:card>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        <flux:card class="p-5 shadow-sm sm:p-6">
            <div class="mb-4"><flux:heading size="lg">Current and past intakes</flux:heading><flux:text class="mt-1">Select a period to inspect intake quota and distribution.</flux:text></div>
            <div class="space-y-3">
                @forelse ($this->periods as $period)
                    <div class="rounded-xl border p-4 transition {{ (string) $selectedPeriodId === (string) $period->id ? 'border-blue-300 bg-blue-50 ring-1 ring-blue-200' : 'border-slate-200 bg-white' }}" wire:key="intake-period-{{ $period->id }}">
                        <button type="button" wire:click="$set('selectedPeriodId', '{{ $period->id }}')" class="block w-full text-left">
                            <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $period->code }}</p><p class="mt-1 font-semibold text-slate-900">{{ $period->name }}</p><p class="mt-1 text-sm text-slate-500">{{ $period->starts_at->format('M d, Y') }} – {{ $period->ends_at->format('M d, Y') }}</p></div><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $period->effective_locked ? 'bg-rose-100 text-rose-700' : ($period->starts_at->gt(today()) ? 'bg-blue-100 text-blue-700' : ($period->ends_at->lt(today()) ? 'bg-slate-100 text-slate-600' : 'bg-emerald-100 text-emerald-700')) }}">{{ $period->effective_locked ? ($period->is_full ? 'Full · Locked' : 'Locked') : ($period->starts_at->gt(today()) ? 'Upcoming' : ($period->ends_at->lt(today()) ? 'Past' : 'Open')) }}</span></div>
                            <div class="mt-4 flex items-center justify-between gap-3 text-sm"><span class="text-slate-600">{{ $period->actual_count }} / {{ $period->quota }} applications</span><span class="font-semibold text-slate-800">{{ min(100, (int) round(($period->actual_count / max(1, $period->quota)) * 100)) }}%</span></div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $period->effective_locked ? 'bg-rose-500' : 'bg-blue-600' }}" style="width: {{ min(100, ($period->actual_count / max(1, $period->quota)) * 100) }}%"></div></div>
                        </button>
                        <div class="mt-3 flex flex-wrap gap-2"><flux:button size="sm" variant="ghost" wire:click="edit({{ $period->id }})">Edit</flux:button><flux:button size="sm" variant="ghost" wire:click="toggleLock({{ $period->id }})">{{ $period->is_locked ? 'Reopen period' : 'Lock period' }}</flux:button></div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 p-8 text-center"><flux:heading size="md">No intake periods yet</flux:heading><flux:text class="mt-2">Create the first intake period to track quota and intake activity.</flux:text></div>
                @endforelse
            </div>
        </flux:card>

        <div class="space-y-6">
            <flux:card class="p-5 shadow-sm sm:p-6">
                <flux:heading size="lg">Intake quota vs actual</flux:heading>
                @if ($this->selectedPeriod)
                    @php($period = $this->selectedPeriod)
                    @php($actual = $this->selectedApplications->count())
                    <div class="mt-4 flex items-end justify-between gap-4"><div><p class="text-sm text-slate-500">{{ $period->name }}</p><p class="mt-1 text-3xl font-black text-slate-900">{{ $actual }} <span class="text-base font-semibold text-slate-400">/ {{ $period->quota }}</span></p></div><span class="rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-700">{{ min(100, (int) round(($actual / max(1, $period->quota)) * 100)) }}% filled</span></div>
                    <div class="mt-4 h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $actual >= $period->quota ? 'bg-rose-500' : 'bg-blue-600' }}" style="width: {{ min(100, ($actual / max(1, $period->quota)) * 100) }}%"></div></div>
                    @if ($actual >= $period->quota)<p class="mt-3 text-sm font-semibold text-rose-700">Quota reached. The period should be locked for new submissions.</p>@endif
                @else
                    <flux:text class="mt-3">Create an intake period to view quota progress.</flux:text>
                @endif
            </flux:card>

            <flux:card class="p-5 shadow-sm sm:p-6">
                <flux:heading size="lg">Distribution by institution</flux:heading>
                <div class="mt-4 space-y-3">
                    @forelse ($this->institutionDistribution as $item)
                        <div><div class="flex justify-between gap-3 text-sm"><span class="font-medium text-slate-700">{{ $item['name'] }}</span><span class="font-bold text-slate-900">{{ $item['count'] }}</span></div><div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-600" style="width: {{ $this->selectedApplications->count() ? ($item['count'] / $this->selectedApplications->count()) * 100 : 0 }}%"></div></div></div>
                    @empty <p class="text-sm text-slate-400">No submitted applications for this period.</p> @endforelse
                </div>
            </flux:card>

            <flux:card class="p-5 shadow-sm sm:p-6">
                <flux:heading size="lg">Distribution by course</flux:heading>
                <div class="mt-4 space-y-3">
                    @forelse ($this->courseDistribution as $item)
                        <div><div class="flex justify-between gap-3 text-sm"><span class="font-medium text-slate-700">{{ $item['name'] }}</span><span class="font-bold text-slate-900">{{ $item['count'] }}</span></div><div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-cyan-600" style="width: {{ $this->selectedApplications->count() ? ($item['count'] / $this->selectedApplications->count()) * 100 : 0 }}%"></div></div></div>
                    @empty <p class="text-sm text-slate-400">No course distribution available yet.</p> @endforelse
                </div>
            </flux:card>
        </div>
    </div>
</div>
