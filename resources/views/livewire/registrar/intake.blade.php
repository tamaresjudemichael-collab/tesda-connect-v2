<?php

use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\IntakeCourseQuota;
use App\Models\IntakePeriod;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, mount, state, title};

layout('layouts::app');
title('Intake Acceptance & Quotas');

state([
    'formOpen' => false,
    'editingId' => null,
    'institution_id' => '',
    'selectedPeriodId' => '',
    'code' => '',
    'name' => '',
    'starts_at' => '',
    'ends_at' => '',
    'quota' => 100,
    'notes' => '',
    'quotaCourseId' => '',
    'courseQuota' => 20,
]);

$institutionIds = computed(fn () => InstitutionScope::idsFor(auth()->user()));
$institutions = computed(fn () => \App\Models\Institution::query()->whereIn('id', $this->institutionIds)->where('is_active', true)->orderBy('name')->get());
$courses = computed(fn () => Course::query()->with('institution')->whereIn('institution_id', $this->institutionIds)->where('is_active', true)->when($this->selectedPeriod, fn ($query) => $query->where('institution_id', $this->selectedPeriod->institution_id), fn ($query) => $query->when($this->institution_id, fn ($nested) => $nested->where('institution_id', (int) $this->institution_id)))->orderBy('name')->get());

$actualCountFor = function (IntakePeriod $period): int {
    return EnrollmentApplication::query()
        ->whereNull('archived_at')
        ->whereNotNull('submitted_at')
        ->where('submitted_at', '>=', $period->starts_at->copy()->startOfDay())
        ->where('submitted_at', '<=', $period->ends_at->copy()->endOfDay())
        ->when($period->institution_id, fn ($query) => $query->where('institution_id', $period->institution_id))
        ->count();
};

$periods = computed(function () use ($actualCountFor) {
    return IntakePeriod::query()
        ->with('institution')
        ->where(function ($query): void {
            $query->whereIn('institution_id', $this->institutionIds);
        })
        ->orderByDesc('starts_at')->get()
        ->map(function (IntakePeriod $period) use ($actualCountFor) {
            $actual = $actualCountFor($period);
            $period->setAttribute('actual_count', $actual);
            $period->setAttribute('effective_locked', $period->is_locked || ($period->quota > 0 && $actual >= $period->quota));
            return $period;
        });
});

mount(function (): void {
    $this->institution_id = (string) ($this->institutions->first()?->id ?? '');
    $this->selectedPeriodId = (string) (IntakePeriod::query()
        ->where(function ($query): void {
            $query->whereIn('institution_id', $this->institutionIds);
        })
        ->whereDate('starts_at', '<=', today())->whereDate('ends_at', '>=', today())
        ->orderByRaw('CASE WHEN institution_id IS NULL THEN 1 ELSE 0 END')
        ->value('id') ?? '');
});

$selectedPeriod = computed(fn () => filled($this->selectedPeriodId)
    ? IntakePeriod::query()->with('institution')->where(function ($query): void {
        $query->whereIn('institution_id', $this->institutionIds);
    })->find($this->selectedPeriodId)
    : null);

$courseQuotaRows = computed(function () use ($actualCountFor) {
    $period = $this->selectedPeriod;
    if (! $period) return collect();

    return IntakeCourseQuota::query()->with(['course.institution'])
        ->where('intake_period_id', $period->id)
        ->whereHas('course', fn ($query) => $query->whereIn('institution_id', $this->institutionIds))
        ->orderBy('course_id')->get()->map(function (IntakeCourseQuota $row) use ($period) {
            $actual = EnrollmentApplication::query()->whereNull('archived_at')->whereNotNull('submitted_at')
                ->where('course_id', $row->course_id)
                ->whereBetween('submitted_at', [$period->starts_at->copy()->startOfDay(), $period->ends_at->copy()->endOfDay()])
                ->when($period->institution_id, fn ($query) => $query->where('institution_id', $period->institution_id))
                ->count();
            $row->setAttribute('actual_count', $actual);
            $row->setAttribute('remaining', max(0, $row->quota - $actual));
            return $row;
        });
});

$resetForm = function (): void {
    $this->reset('editingId', 'code', 'name', 'starts_at', 'ends_at', 'notes');
    $this->institution_id = (string) ($this->institutions->first()?->id ?? '');
    $this->quota = 100;
    $this->resetValidation();
};

$openCreate = function (): void {
    $this->resetForm();
    $this->formOpen = true;
};

$edit = function (int $id): void {
    $period = IntakePeriod::query()->where(function ($query): void {
        $query->whereIn('institution_id', $this->institutionIds);
    })->findOrFail($id);
    $this->editingId = $period->id;
    $this->selectedPeriodId = (string) $period->id;
    $this->institution_id = (string) ($period->institution_id ?? $this->institutions->first()?->id ?? '');
    $this->code = $period->code;
    $this->name = $period->name;
    $this->starts_at = $period->starts_at->toDateString();
    $this->ends_at = $period->ends_at->toDateString();
    $this->quota = $period->quota;
    $this->notes = $period->notes ?? '';
    $this->formOpen = true;
};

$savePeriod = function () use ($actualCountFor): void {
    $this->code = strtoupper(trim($this->code));
    $validated = $this->validate([
        'institution_id' => ['required', Rule::in($this->institutionIds->map(fn ($id) => (string) $id)->all())],
        'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]{2,40}$/', Rule::unique('intake_periods', 'code')->ignore($this->editingId)],
        'name' => ['required', 'string', 'max:120'],
        'starts_at' => ['required', 'date'],
        'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        'quota' => ['required', 'integer', 'min:1', 'max:1000000'],
        'notes' => ['nullable', 'string', 'max:2000'],
    ]);

    $overlap = IntakePeriod::query()->where('institution_id', (int) $validated['institution_id'])
        ->whereDate('starts_at', '<=', $validated['ends_at'])
        ->whereDate('ends_at', '>=', $validated['starts_at'])
        ->when($this->editingId, fn ($query) => $query->where('id', '!=', $this->editingId))->exists();
    if ($overlap) {
        $this->addError('starts_at', 'Another intake period for this institution overlaps these dates.');
        return;
    }

    $attributes = [
        'institution_id' => (int) $validated['institution_id'],
        'code' => $validated['code'], 'name' => trim($validated['name']),
        'starts_at' => $validated['starts_at'], 'ends_at' => $validated['ends_at'],
        'quota' => (int) $validated['quota'], 'notes' => $validated['notes'] ?: null,
    ];
    $isEditing = filled($this->editingId);
    if ($isEditing) {
        $period = IntakePeriod::query()->where(function ($query): void {
            $query->whereIn('institution_id', $this->institutionIds);
        })->findOrFail($this->editingId);
        $before = ActivityLogger::snapshot($period, ['institution_id', 'code', 'name', 'starts_at', 'ends_at', 'quota', 'is_locked']);
        $period->update($attributes);
        $changes = ActivityLogger::changes($period, $before);
    } else {
        $period = IntakePeriod::create($attributes + ['created_by_user_id' => auth()->id(), 'is_locked' => false]);
        $changes = [];
    }

    ActivityLogger::record($isEditing ? 'intake_period_updated' : 'intake_period_created', ($isEditing ? 'Updated' : 'Created').' institution intake '.$period->name.'.', (int) $period->institution_id, ['intake_period_id' => $period->id, 'changes' => $changes]);
    $this->selectedPeriodId = (string) $period->id;
    $this->resetForm(); $this->formOpen = false;
    unset($this->periods, $this->selectedPeriod, $this->courseQuotaRows);
    Flux::toast(variant: 'success', text: 'Intake period saved.');
};

$toggleLock = function (int $id) use ($actualCountFor): void {
    $period = IntakePeriod::query()->where(function ($query): void {
        $query->whereIn('institution_id', $this->institutionIds);
    })->findOrFail($id);
    $actual = $actualCountFor($period);
    if ($period->is_locked && $period->quota > 0 && $actual >= $period->quota) {
        Flux::toast(variant: 'warning', text: 'This intake is full. Increase its quota before reopening it.');
        return;
    }
    $before = ActivityLogger::snapshot($period, ['is_locked', 'locked_at', 'locked_by_user_id']);
    $closing = ! $period->is_locked;
    $period->forceFill(['is_locked' => $closing, 'locked_at' => $closing ? now() : null, 'locked_by_user_id' => $closing ? auth()->id() : null])->save();
    ActivityLogger::record($closing ? 'intake_period_closed' : 'intake_period_opened', ($closing ? 'Closed' : 'Opened').' intake '.$period->name.'.', (int) $period->institution_id, ['intake_period_id' => $period->id, 'changes' => ActivityLogger::changes($period, $before)]);
    unset($this->periods, $this->selectedPeriod, $this->courseQuotaRows);
    Flux::toast(variant: 'success', text: $closing ? 'Intake acceptance closed.' : 'Intake acceptance opened.');
};

$saveCourseQuota = function (): void {
    $validated = $this->validate([
        'selectedPeriodId' => ['required', 'integer', Rule::exists('intake_periods', 'id')],
        'quotaCourseId' => ['required', 'integer', Rule::exists('courses', 'id')->where(fn ($query) => $query->whereIn('institution_id', $this->institutionIds)->where('is_active', true))],
        'courseQuota' => ['required', 'integer', 'min:1', 'max:100000'],
    ]);
    $period = $this->selectedPeriod;
    abort_unless($period, 404);
    abort_unless(Course::query()->whereKey((int) $validated['quotaCourseId'])->where('institution_id', (int) $period->institution_id)->exists(), 403, 'Course quota must belong to the selected institution intake.');
    $quota = IntakeCourseQuota::updateOrCreate(
        ['intake_period_id' => $period->id, 'course_id' => (int) $validated['quotaCourseId']],
        ['quota' => (int) $validated['courseQuota'], 'created_by_user_id' => auth()->id()]
    );
    ActivityLogger::record('intake_course_quota_updated', 'Set intake quota for '.$quota->course->name.'.', (int) $quota->course->institution_id, ['intake_period_id' => $period->id, 'course_id' => $quota->course_id, 'quota' => $quota->quota]);
    $this->quotaCourseId = ''; $this->courseQuota = 20;
    unset($this->courseQuotaRows);
    Flux::toast(variant: 'success', text: 'Course quota saved.');
};

$deleteCourseQuota = function (int $id): void {
    $row = IntakeCourseQuota::query()->with('course')->whereHas('intakePeriod', fn ($query) => $query->whereIn('institution_id', $this->institutionIds))->whereHas('course', fn ($query) => $query->whereIn('institution_id', $this->institutionIds))->findOrFail($id);
    $hasSubmitted = EnrollmentApplication::query()->where('course_id', $row->course_id)->whereNotNull('submitted_at')->exists();
    if ($hasSubmitted) {
        Flux::toast(variant: 'warning', text: 'A course quota with existing submitted applications cannot be removed. Edit the quota instead.');
        return;
    }
    $row->delete(); unset($this->courseQuotaRows);
    Flux::toast(variant: 'success', text: 'Course quota removed.');
};
?>

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-semibold text-blue-700">Enrollment Management</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Intake Acceptance & Quotas</flux:heading><flux:text class="mt-1">Open or close application windows, set total and per-course quotas, and monitor remaining slots for your assigned institution(s).</flux:text></div><flux:button variant="primary" icon="plus" wire:click="openCreate">New intake period</flux:button></div>

    @if ($formOpen)
        <flux:card class="bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><flux:heading size="lg">{{ $editingId ? 'Edit intake period' : 'Create intake period' }}</flux:heading><flux:button variant="ghost" icon="x-mark" wire:click="$set('formOpen', false)" /></div>
            <form wire:submit="savePeriod" class="mt-5 grid gap-4 md:grid-cols-2">
                <flux:select wire:model="institution_id" label="Institution" required>@foreach ($this->institutions as $institution)<flux:select.option value="{{ $institution->id }}">{{ $institution->code }} — {{ $institution->name }}</flux:select.option>@endforeach</flux:select>
                <flux:input wire:model="code" label="Period code" required />
                <flux:input wire:model="name" label="Period name" required />
                <flux:input wire:model="quota" type="number" min="1" label="Overall quota" required />
                <flux:input wire:model="starts_at" type="date" label="Starts on" required />
                <flux:input wire:model="ends_at" type="date" label="Ends on" required />
                <div class="md:col-span-2"><flux:textarea wire:model="notes" label="Notes (optional)" rows="2" /></div>
                @error('starts_at')<p class="text-sm text-rose-700 md:col-span-2">{{ $message }}</p>@enderror
                <div class="flex justify-end gap-2 md:col-span-2"><flux:button type="button" variant="ghost" wire:click="$set('formOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Save period</flux:button></div>
            </form>
        </flux:card>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card class="bg-white p-5 shadow-sm"><flux:heading size="lg">Intake periods</flux:heading><div class="mt-4 space-y-3">
            @forelse ($this->periods as $period)
                <article class="rounded-xl border border-slate-200 p-4" wire:key="intake-period-{{ $period->id }}"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="font-bold text-slate-900">{{ $period->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $period->institution?->code ?? 'Global' }} · {{ $period->starts_at->format('M j, Y') }} – {{ $period->ends_at->format('M j, Y') }}</p><p class="mt-2 text-sm text-slate-600">{{ $period->actual_count }} / {{ $period->quota }} applications · {{ max(0, $period->quota - $period->actual_count) }} remaining</p></div><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $period->effective_locked ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">{{ $period->effective_locked ? 'Closed' : 'Open' }}</span></div><div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full {{ $period->effective_locked ? 'bg-rose-500' : 'bg-blue-600' }}" style="width: {{ min(100, (int) round(($period->actual_count / max(1, $period->quota)) * 100)) }}%"></div></div><div class="mt-3 flex flex-wrap gap-2"><flux:button size="sm" variant="ghost" wire:click="edit({{ $period->id }})">Edit</flux:button><flux:button size="sm" variant="outline" wire:click="$set('selectedPeriodId', '{{ $period->id }}')">Manage course quotas</flux:button><flux:button size="sm" variant="{{ $period->is_locked ? 'primary' : 'danger' }}" wire:click="toggleLock({{ $period->id }})" wire:confirm="{{ $period->is_locked ? 'Open' : 'Close' }} this intake?">{{ $period->is_locked ? 'Open intake' : 'Close intake' }}</flux:button></div></article>
            @empty<div class="rounded-xl border border-dashed p-8 text-center"><p class="font-semibold">No intake period configured</p><p class="mt-1 text-sm text-slate-500">Create a period before accepting new submissions.</p></div>@endforelse
        </div></flux:card>

        <flux:card class="bg-white p-5 shadow-sm"><flux:heading size="lg">Per-course quota</flux:heading><flux:text class="mt-1">{{ $this->selectedPeriod?->name ?? 'Select an intake period first' }}</flux:text>
            @if ($this->selectedPeriod)
                <form wire:submit="saveCourseQuota" class="mt-4 space-y-3 rounded-xl bg-slate-50 p-4"><flux:select wire:model="quotaCourseId" label="Course" required><flux:select.option value="">Choose course</flux:select.option>@foreach ($this->courses as $course)<flux:select.option value="{{ $course->id }}">{{ $course->institution?->code }} · {{ $course->name }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="courseQuota" type="number" min="1" label="Course quota" required/><div class="flex justify-end"><flux:button type="submit" variant="primary">Save course quota</flux:button></div></form>
                <div class="mt-4 space-y-3">@forelse ($this->courseQuotaRows as $row)<div class="rounded-xl border border-slate-200 p-4" wire:key="course-quota-{{ $row->id }}"><div class="flex justify-between gap-2"><div><p class="font-semibold text-slate-900">{{ $row->course->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $row->actual_count }} / {{ $row->quota }} submitted · {{ $row->remaining }} remaining</p></div><flux:button size="xs" variant="ghost" wire:click="deleteCourseQuota({{ $row->id }})" wire:confirm="Remove this quota?">Remove</flux:button></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full {{ $row->remaining === 0 ? 'bg-rose-500' : 'bg-cyan-600' }}" style="width: {{ min(100, (int) round(($row->actual_count / max(1, $row->quota)) * 100)) }}%"></div></div></div>@empty<p class="mt-5 rounded-xl border border-dashed p-6 text-center text-sm text-slate-500">No per-course quotas yet. Add one above to apply a course-specific cap.</p>@endforelse</div>
            @else<div class="mt-5 rounded-xl border border-dashed p-8 text-center text-sm text-slate-500">Create or select an intake period to configure course quotas.</div>@endif
        </flux:card>
    </div>
</div>
