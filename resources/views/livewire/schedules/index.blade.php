<?php

use App\Models\Course;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Support\InstitutionScope;
use Flux\Flux;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Scheduling Management');

state([
    'formOpen' => false,
    'editingId' => null,
    'institution_id' => null,
    'course_id' => null,
    'code' => '',
    'name' => '',
    'capacity' => 25,
    'starts_at' => '',
    'ends_at' => '',
    'next_class_at' => '',
    'venue' => '',
    'status' => 'upcoming',
]);


$institutionIds = computed(fn () => InstitutionScope::idsFor(auth()->user()));

$institutions = computed(fn () => Institution::query()
    ->whereIn('id', $this->institutionIds)
    ->where('is_active', true)
    ->orderBy('name')
    ->get());

$courses = computed(fn () => Course::query()->where('institution_id', $this->institution_id)->where('is_active', true)->orderBy('name')->get());
$batches = computed(fn () => TrainingBatch::query()->with('course')->whereIn('institution_id', $this->institutionIds)->orderBy('starts_at')->get());

$resetForm = function (): void {
    $this->reset('editingId', 'institution_id', 'course_id', 'code', 'name', 'capacity', 'starts_at', 'ends_at', 'next_class_at', 'venue', 'status');
    $this->capacity = 25;
    $this->status = 'upcoming';
    $this->resetValidation();
};

$openCreate = function (): void {
    $this->resetForm();
    $this->institution_id = $this->institutions->first()?->id;
    $this->formOpen = true;
};

$updatedInstitutionId = function (): void {
    $this->course_id = null;
    unset($this->courses);
};

$edit = function (int $id): void {
    $batch = TrainingBatch::findOrFail($id);
    abort_unless($this->institutionIds->contains($batch->institution_id), 403);

    $this->resetValidation();
    $this->editingId = $batch->id;
    $this->institution_id = $batch->institution_id;
    $this->course_id = $batch->course_id;
    $this->code = $batch->code;
    $this->name = $batch->name;
    $this->capacity = $batch->capacity;
    $this->starts_at = $batch->starts_at?->toDateString() ?? '';
    $this->ends_at = $batch->ends_at?->toDateString() ?? '';
    $this->next_class_at = $batch->next_class_at?->format('Y-m-d\TH:i') ?? '';
    $this->venue = $batch->venue ?? '';
    $this->status = $batch->status;
    unset($this->courses);
    $this->formOpen = true;
};

$save = function (): void {
    $validated = $this->validate([
        'institution_id' => [
            'required',
            Rule::in($this->institutions->pluck('id')->all()),
        ],
        'course_id' => ['required', Rule::exists('courses', 'id')->where(fn ($query) => $query->where('institution_id', $this->institution_id)->where('is_active', true))],
        'code' => ['required', 'string', 'max:40', Rule::unique('training_batches', 'code')->ignore($this->editingId)],
        'name' => ['required', 'string', 'max:255'],
        'capacity' => ['required', 'integer', 'min:1', 'max:500'],
        'starts_at' => ['required', 'date'],
        'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        'next_class_at' => ['nullable', 'date'],
        'venue' => ['nullable', 'string', 'max:255'],
        'status' => ['required', Rule::in(['upcoming', 'in_training', 'completed', 'cancelled'])],
    ]);

    if ($this->editingId) {
        $batch = TrainingBatch::findOrFail($this->editingId);
        abort_unless($this->institutionIds->contains($batch->institution_id), 403);
        $batch->update($validated);
    } else {
        TrainingBatch::create($validated);
    }

    unset($this->batches);
    $this->resetForm();
    $this->formOpen = false;
    Flux::toast(variant: 'success', text: 'Batch schedule saved.');
};

$delete = function (int $id): void {
    $batch = TrainingBatch::findOrFail($id);
    abort_unless($this->institutionIds->contains($batch->institution_id), 403);
    $batch->delete();
    unset($this->batches);
    Flux::toast(variant: 'success', text: 'Batch schedule deleted.');
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-semibold text-cyan-600">Operations workspace</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Scheduling Management</flux:heading><flux:text class="mt-1">Coordinate batches, orientation dates, and the training calendar.</flux:text></div><flux:button variant="primary" icon="calendar-days" wire:click="openCreate">Add schedule</flux:button></div>

    @if ($formOpen)
        <flux:card class="bg-white p-6 shadow-sm"><div class="flex items-start justify-between gap-4"><div><flux:heading size="lg">{{ $editingId ? 'Edit batch schedule' : 'Add batch schedule' }}</flux:heading><flux:text class="mt-1">Create or update the training batch details.</flux:text></div><flux:button variant="ghost" icon="x-mark" wire:click="$set('formOpen', false)" /></div><form wire:submit="save" class="mt-6 grid gap-5 md:grid-cols-2 lg:grid-cols-3"><flux:select wire:model.live="institution_id" label="Institution" required><flux:select.option value="">Select institution</flux:select.option>@foreach($this->institutions as $institution)<flux:select.option value="{{ $institution->id }}">{{ $institution->name }}</flux:select.option>@endforeach</flux:select>
<flux:select
    wire:key="schedule-course-select-{{ $institution_id ?: 'none' }}"
    wire:model="course_id"
    label="Course"
    required
>
    <flux:select.option value="">Select course</flux:select.option>
    @foreach ($this->courses as $course)
        <flux:select.option :value="$course->id">
            {{ $course->code }} — {{ $course->name }}
        </flux:select.option>
    @endforeach
</flux:select>
            <flux:input wire:model="code" label="Batch code" placeholder="MIF-OAP-2026-03" required /><flux:input wire:model="name" label="Batch name" placeholder="OAP Batch 3" required /><flux:input wire:model="capacity" type="number" min="1" label="Capacity" required /><flux:select wire:model="status" label="Status" required><flux:select.option value="upcoming">Upcoming</flux:select.option><flux:select.option value="in_training">In training</flux:select.option><flux:select.option value="completed">Completed</flux:select.option><flux:select.option value="cancelled">Cancelled</flux:select.option></flux:select><flux:input wire:model="starts_at" type="date" label="Start date" required /><flux:input wire:model="ends_at" type="date" label="End date" required /><flux:input wire:model="next_class_at" type="datetime-local" label="Next class date and time (optional)" /><flux:input wire:model="venue" label="Class venue (optional)" placeholder="Training room / venue" /><div class="flex items-end justify-end gap-3"><flux:button variant="ghost" type="button" wire:click="resetForm">Reset</flux:button><flux:button variant="primary" type="submit">Save schedule</flux:button></div></form></flux:card>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">@forelse($this->batches as $batch)<flux:card class="bg-white p-6 shadow-sm"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-bold tracking-wide text-cyan-600">{{ $batch->code }}</p><flux:heading size="lg" class="mt-1">{{ $batch->name }}</flux:heading><flux:text class="mt-1">{{ $batch->course?->name }}</flux:text></div><span class="rounded-full px-3 py-1 text-xs font-bold {{ $batch->status === 'in_training' ? 'bg-emerald-50 text-emerald-700' : ($batch->status === 'cancelled' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">{{ str($batch->status)->headline() }}</span></div><div class="mt-5 grid grid-cols-2 gap-3 border-y border-slate-100 py-4 text-sm"><div><p class="text-xs text-slate-400">Schedule</p><p class="mt-1 font-semibold text-slate-700">{{ $batch->starts_at?->format('M d, Y') }} – {{ $batch->ends_at?->format('M d, Y') }}</p></div><div><p class="text-xs text-slate-400">Capacity</p><p class="mt-1 font-semibold text-slate-700">{{ $batch->applications()->count() }} / {{ $batch->capacity }} trainees</p></div></div><div class="mt-4 rounded-xl bg-blue-50 p-3 text-sm"><p class="font-semibold text-blue-900">Next class: {{ $batch->next_class_at?->format('M d, Y · h:i A') ?? 'Not scheduled' }}</p><p class="mt-1 text-blue-800">Venue: {{ $batch->venue ?: 'Not announced' }}</p></div><div class="mt-5 flex justify-end gap-2"><flux:button variant="filled" size="sm" wire:click="edit({{ $batch->id }})">Edit</flux:button><flux:button variant="danger" size="sm" wire:click="delete({{ $batch->id }})" wire:confirm="Delete {{ $batch->name }}? Assigned application records will remain but lose their batch assignment.">Delete</flux:button></div></flux:card>@empty <flux:card class="col-span-full p-12 text-center text-slate-400">No batches have been created yet.</flux:card>@endforelse</div>
</div>
