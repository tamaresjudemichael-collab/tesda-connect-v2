<?php

use App\Models\Course;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Support\ActivityLogger;
use Flux\Flux;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Batch Management');

state([
    'formOpen' => false,
    'editingId' => null,
    'institution_id' => '',
    'course_id' => '',
    'code' => '',
    'name' => '',
    'capacity' => '25',
    'scheduled_hours' => '',
    'starts_at' => '',
    'ends_at' => '',
    'status' => 'upcoming',
]);

$institutions = computed(fn () => Institution::query()
    ->where('is_active', true)
    ->orderBy('name')
    ->get());

$courses = computed(function () {
    if (! $this->institution_id) {
        return collect();
    }

    return Course::query()
        ->where('institution_id', $this->institution_id)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();
});

$batches = computed(fn () => TrainingBatch::query()
    ->with(['institution', 'course'])
    ->withCount('applications')
    ->orderByDesc('created_at')
    ->get());

$updatedInstitutionId = function (): void {
    $this->course_id = '';
};

$resetForm = function (): void {
    $this->reset(
        'editingId',
        'institution_id',
        'course_id',
        'code',
        'name',
        'capacity',
        'scheduled_hours',
        'starts_at',
        'ends_at',
        'status'
    );

    $this->capacity = '25';
    $this->scheduled_hours = '';
    $this->status = 'upcoming';
    $this->resetValidation();
};

$openCreate = function (): void {
    $this->resetForm();
    $this->formOpen = true;
};

$edit = function (int $id): void {
    $batch = TrainingBatch::findOrFail($id);

    $this->resetValidation();
    $this->editingId = $batch->id;
    $this->institution_id = (string) $batch->institution_id;
    $this->course_id = (string) $batch->course_id;
    $this->code = $batch->code;
    $this->name = $batch->name;
    $this->capacity = (string) $batch->capacity;
    $this->scheduled_hours = $batch->scheduled_hours !== null ? (string) $batch->scheduled_hours : '';
    $this->starts_at = $batch->starts_at?->format('Y-m-d') ?? '';
    $this->ends_at = $batch->ends_at?->format('Y-m-d') ?? '';
    $this->status = $batch->status;
    $this->formOpen = true;
};

$save = function (): void {
    $wasEditing = filled($this->editingId);

    $this->code = strtoupper(trim($this->code));

    $validated = $this->validate([
        'institution_id' => [
            'required',
            Rule::exists('institutions', 'id')->where('is_active', true),
        ],
        'course_id' => [
            'required',
            Rule::exists('courses', 'id')
                ->where('institution_id', $this->institution_id)
                ->where('is_active', true),
        ],
        'code' => [
            'required',
            'string',
            'max:40',
            'regex:/^[A-Z0-9-]{2,40}$/',
            Rule::unique('training_batches', 'code')->ignore($this->editingId),
        ],
        'name' => ['required', 'string', 'max:255'],
        'capacity' => ['required', 'integer', 'min:1', 'max:65535'],
        'scheduled_hours' => ['nullable', 'integer', 'min:1', 'max:65535'],
        'starts_at' => ['nullable', 'date'],
        'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        'status' => [
            'required',
            Rule::in(['upcoming', 'ongoing', 'completed', 'cancelled']),
        ],
    ], [
        'institution_id.required' => 'Please select an institution.',
        'institution_id.exists' => 'Please select an active institution.',
        'course_id.required' => 'Please select a course.',
        'course_id.exists' => 'The selected course must belong to the selected institution and be active.',
        'code.regex' => 'The batch code may contain uppercase letters, numbers, and hyphens only.',
        'ends_at.after_or_equal' => 'The end date must be on or after the start date.',
    ]);

    $attributes = [
        'institution_id' => $validated['institution_id'],
        'course_id' => $validated['course_id'],
        'code' => $validated['code'],
        'name' => trim($validated['name']),
        'capacity' => $validated['capacity'],
        'scheduled_hours' => $validated['scheduled_hours'] ?: null,
        'starts_at' => $validated['starts_at'] ?: null,
        'ends_at' => $validated['ends_at'] ?: null,
        'status' => $validated['status'],
    ];

$previousStatus = null;
$before = [];

if ($wasEditing) {
    $batch = TrainingBatch::findOrFail($this->editingId);
    $previousStatus = $batch->status;
    $before = ActivityLogger::snapshot($batch, ['institution_id', 'course_id', 'code', 'name', 'capacity', 'scheduled_hours', 'starts_at', 'ends_at', 'status']);
    $batch->update($attributes);
    $changes = ActivityLogger::changes($batch, $before);
} else {
    $batch = TrainingBatch::create($attributes);
    $changes = [];
}

ActivityLogger::record(
    $wasEditing ? 'batch_updated' : 'batch_created',
    ($wasEditing ? 'Updated' : 'Created').' training batch '.$batch->name,
    (int) $batch->institution_id,
    [
        'batch_id' => $batch->id,
        'batch_code' => $batch->code,
        'course_id' => $batch->course_id,
        'status' => $batch->status,
        'changes' => $changes,
    ]
);
if ($wasEditing && $previousStatus !== $batch->status) {
    ActivityLogger::record(
        'batch_status_changed',
        'Changed training batch '.$batch->name.' status from '.$previousStatus.' to '.$batch->status,
        (int) $batch->institution_id,
        [
            'batch_id' => $batch->id,
            'batch_code' => $batch->code,
            'previous_status' => $previousStatus,
            'new_status' => $batch->status,
            'changes' => ['status' => ['from' => $previousStatus, 'to' => $batch->status]],
        ]
    );
}
    unset($this->batches);

    $this->resetForm();
    $this->formOpen = false;

    Flux::toast(
        variant: 'success',
        text: $wasEditing
            ? 'Training batch updated successfully.'
            : 'Training batch created successfully.'
    );
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-blue-700">Training Operations</p>
            <flux:heading size="xl" class="mt-1 text-3xl font-black text-slate-900">
                Batch Management
            </flux:heading>
            <flux:text class="mt-1">
                Create and manage training batches across registered institutions.
            </flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreate">
            Create batch
        </flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="bg-white p-5 shadow-sm">
            <flux:text>Registered batches</flux:text>
            <flux:heading size="xl" class="mt-2 text-3xl font-black">
                {{ $this->batches->count() }}
            </flux:heading>
        </flux:card>

        <flux:card class="bg-white p-5 shadow-sm">
            <flux:text>Upcoming batches</flux:text>
            <flux:heading size="xl" class="mt-2 text-3xl font-black">
                {{ $this->batches->where('status', 'upcoming')->count() }}
            </flux:heading>
        </flux:card>

        <flux:card class="bg-white p-5 shadow-sm">
            <flux:text>Ongoing batches</flux:text>
            <flux:heading size="xl" class="mt-2 text-3xl font-black">
                {{ $this->batches->where('status', 'ongoing')->count() }}
            </flux:heading>
        </flux:card>
    </div>

    @if ($formOpen)
        <flux:card class="bg-white p-5 shadow-sm sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading size="lg">
                        {{ $editingId ? 'Edit training batch' : 'Create training batch' }}
                    </flux:heading>
                    <flux:text class="mt-1">
                        Complete the batch details below.
                    </flux:text>
                </div>

                <flux:button
                    variant="ghost"
                    icon="x-mark"
                    wire:click="$set('formOpen', false)"
                    aria-label="Close form"
                />
            </div>

            <form wire:submit="save" class="mt-6 space-y-5">
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:select
                        wire:model.live="institution_id"
                        label="Institution"
                        placeholder="Select an institution"
                        required
                    >
                        @foreach ($this->institutions as $institution)
                            <flux:select.option :value="$institution->id">
                                {{ $institution->name }} ({{ $institution->code }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select
                        wire:model="course_id"
                        label="Training course"
                        placeholder="Select a course"
                        required
                    >
                        @foreach ($this->courses as $course)
                            <flux:select.option :value="$course->id">
                                {{ $course->code }} — {{ $course->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($institution_id && $this->courses->isEmpty())
                        <div class="md:col-span-2">
                            <p class="text-sm text-amber-700">
                                No active courses are available for this institution. Add a course first before creating a batch.
                            </p>
                        </div>
                    @endif

                    <flux:input
                        wire:model="code"
                        label="Batch code"
                        placeholder="e.g. BST-BPP-2026-01"
                        required
                    />

                    <flux:input
                        wire:model="name"
                        label="Batch name"
                        placeholder="e.g. BPP NC II — Batch 01"
                        required
                    />

                    <flux:input
                        wire:model="capacity"
                        label="Maximum capacity"
                        type="number"
                        min="1"
                        max="65535"
                        required
                    />

                    <flux:input
                        wire:model="scheduled_hours"
                        label="Scheduled training hours"
                        type="number"
                        min="1"
                        max="65535"
                        placeholder="e.g. 360"
                    />

                    <flux:select wire:model="status" label="Batch status" required>
                        <flux:select.option value="upcoming">Upcoming</flux:select.option>
                        <flux:select.option value="ongoing">Ongoing</flux:select.option>
                        <flux:select.option value="completed">Completed</flux:select.option>
                        <flux:select.option value="cancelled">Cancelled</flux:select.option>
                    </flux:select>

                    <flux:input
                        wire:model="starts_at"
                        label="Training start date"
                        type="date"
                    />

                    <flux:input
                        wire:model="ends_at"
                        label="Training end date"
                        type="date"
                    />
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-slate-100 pt-5">
                    <flux:button
                        variant="ghost"
                        type="button"
                        wire:click="resetForm"
                    >
                        Reset form
                    </flux:button>

                    <flux:button
                        variant="primary"
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        {{ $editingId ? 'Save changes' : 'Save batch' }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    <flux:card class="bg-white p-5 shadow-sm sm:p-6">
        <div class="mb-5">
            <flux:heading size="lg">Training batches</flux:heading>
            <flux:text class="mt-1">
                View batch schedules, institutions, course assignments, and enrollment counts.
            </flux:text>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[950px] text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-3">Batch details</th>
                        <th class="px-3 py-3">Institution and course</th>
                        <th class="px-3 py-3">Training dates</th>
                        <th class="px-3 py-3">Enrollment</th>
                        <th class="px-3 py-3">Status</th>
                        <th class="px-3 py-3 text-right">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->batches as $batch)
                        <tr wire:key="training-batch-{{ $batch->id }}">
                            <td class="px-3 py-4">
                                <p class="font-bold text-slate-900">
                                    {{ $batch->name }}
                                </p>
                                <p class="mt-1 text-xs font-semibold text-blue-700">
                                    {{ $batch->code }}
                                </p>
                            </td>

                            <td class="px-3 py-4">
                                <p class="font-semibold text-slate-800">
                                    {{ $batch->institution->name }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $batch->course->code }}
                                </p>
                                <p class="mt-1 text-xs text-slate-600">
                                    {{ $batch->course->name }}
                                </p>
                            </td>

                            <td class="px-3 py-4 text-slate-600">
                                <p>Start: {{ $batch->starts_at?->format('M d, Y') ?? 'Not set' }}</p>
                                <p class="mt-1">End: {{ $batch->ends_at?->format('M d, Y') ?? 'Not set' }}</p>
                                <p class="mt-1 text-xs">Hours: {{ $batch->scheduled_hours !== null ? $batch->scheduled_hours : 'Not set' }} / {{ $batch->course?->training_hours !== null ? $batch->course->training_hours : 'Target not set' }}</p>
                            </td>

                            <td class="px-3 py-4">
                                <p class="font-semibold text-slate-800">
                                    {{ $batch->applications_count }} / {{ $batch->capacity }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    Applications
                                </p>
                            </td>

                            <td class="px-3 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ match ($batch->status) {
                                    'ongoing' => 'bg-blue-50 text-blue-700',
                                    'completed' => 'bg-emerald-50 text-emerald-700',
                                    'cancelled' => 'bg-rose-50 text-rose-700',
                                    default => 'bg-amber-50 text-amber-700',
                                } }}">
                                    {{ ucfirst($batch->status) }}
                                </span>
                            </td>

                            <td class="px-3 py-4">
                                <div class="flex justify-end">
                                    <flux:button
                                        size="sm"
                                        variant="filled"
                                        icon="pencil-square"
                                        wire:click="edit({{ $batch->id }})"
                                    >
                                        Edit
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-12 text-center">
                                <p class="font-semibold text-slate-700">
                                    No training batches yet
                                </p>
                                <p class="mt-1 text-slate-500">
                                    Create your first batch to start organizing training schedules.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>