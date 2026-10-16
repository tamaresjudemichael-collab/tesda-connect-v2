
<?php

use App\Models\Course;
use App\Support\ActivityLogger;
use App\Models\Institution;
use Flux\Flux;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Course Management');

state([
    'formOpen' => false,
    'editingId' => null,
    'institution_id' => '',
    'code' => '',
    'name' => '',
    'qualification_level' => '',
    'training_hours' => '',
    'is_active' => '1',
]);

$institutions = computed(fn () => Institution::query()
    ->where('is_active', true)
    ->orderBy('name')
    ->get());

$courses = computed(fn () => Course::query()
    ->with('institution')
    ->withCount('batches')
    ->orderBy('name')
    ->get());

$resetForm = function (): void {
    $this->reset(
        'editingId',
        'institution_id',
        'code',
        'name',
        'qualification_level',
        'training_hours',
        'is_active'
    );

    $this->is_active = '1';
    $this->resetValidation();
};

$openCreate = function (): void {
    $this->resetForm();
    $this->formOpen = true;
};

$edit = function (int $id): void {
    $course = Course::findOrFail($id);

    $this->resetValidation();
    $this->editingId = $course->id;
    $this->institution_id = (string) $course->institution_id;
    $this->code = $course->code;
    $this->name = $course->name;
    $this->qualification_level = $course->qualification_level ?? '';
    $this->training_hours = $course->training_hours !== null
        ? (string) $course->training_hours
        : '';
    $this->is_active = $course->is_active ? '1' : '0';
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
        'code' => [
            'required',
            'string',
            'max:40',
            'regex:/^[A-Z0-9-]{2,40}$/',
            Rule::unique('courses', 'code')
                ->where('institution_id', $this->institution_id)
                ->ignore($this->editingId),
        ],
        'name' => ['required', 'string', 'max:255'],
        'qualification_level' => ['nullable', 'string', 'max:30'],
        'training_hours' => ['nullable', 'integer', 'min:1', 'max:65535'],
        'is_active' => ['required', Rule::in(['1', '0'])],
    ], [
        'institution_id.required' => 'Please select an institution.',
        'institution_id.exists' => 'Please select an active institution.',
        'code.regex' => 'The course code may contain uppercase letters, numbers, and hyphens only.',
        'code.unique' => 'This course code is already registered for the selected institution.',
        'training_hours.integer' => 'Training hours must be a whole number.',
        'training_hours.min' => 'Training hours must be at least 1.',
    ]);

    $attributes = [
        'institution_id' => $validated['institution_id'],
        'code' => $validated['code'],
        'name' => trim($validated['name']),
        'qualification_level' => $validated['qualification_level'] ?: null,
        'training_hours' => $validated['training_hours'] ?: null,
        'is_active' => $validated['is_active'] === '1',
    ];

$before = [];
if ($wasEditing) {
    $course = Course::findOrFail($this->editingId);
    $before = ActivityLogger::snapshot($course, ['institution_id', 'code', 'name', 'qualification_level', 'training_hours', 'is_active']);
    $course->update($attributes);
    $changes = ActivityLogger::changes($course, $before);
} else {
    $course = Course::create($attributes);
    $changes = [];
}

ActivityLogger::record(
    $wasEditing ? 'course_updated' : 'course_created',
    ($wasEditing ? 'Updated' : 'Created').' course '.$course->name,
    (int) $course->institution_id,
    [
        'course_id' => $course->id,
        'code' => $course->code,
        'name' => $course->name,
        'changes' => $changes,
    ]
);

    unset($this->courses);

    $this->resetForm();
    $this->formOpen = false;

    Flux::toast(
        variant: 'success',
        text: $wasEditing
            ? 'Course updated successfully.'
            : 'Course added successfully.'
    );
};

$toggleStatus = function (int $id): void {
    $course = Course::findOrFail($id);
    $before = ActivityLogger::snapshot($course, ['is_active']);

    $course->update([
        'is_active' => ! $course->is_active,
    ]);
ActivityLogger::record(
    $course->is_active ? 'course_activated' : 'course_deactivated',
    ($course->is_active ? 'Activated' : 'Deactivated').' course '.$course->name,
    (int) $course->institution_id,
    [
        'course_id' => $course->id,
        'code' => $course->code,
        'is_active' => (bool) $course->is_active,
        'changes' => ActivityLogger::changes($course, $before),
    ]
);
    unset($this->courses);

    Flux::toast(
        variant: 'success',
        text: $course->is_active
            ? 'Course activated successfully.'
            : 'Course deactivated successfully.'
    );
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-blue-700">Training Operations</p>
            <flux:heading size="xl" class="mt-1 text-3xl font-black text-slate-900">
                Course Management
            </flux:heading>
            <flux:text class="mt-1">
                Manage training courses and qualifications for registered institutions.
            </flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreate">
            Add course
        </flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="bg-white p-5 shadow-sm">
            <flux:text>Registered courses</flux:text>
            <flux:heading size="xl" class="mt-2 text-3xl font-black">
                {{ $this->courses->count() }}
            </flux:heading>
        </flux:card>

        <flux:card class="bg-white p-5 shadow-sm">
            <flux:text>Active courses</flux:text>
            <flux:heading size="xl" class="mt-2 text-3xl font-black">
                {{ $this->courses->where('is_active', true)->count() }}
            </flux:heading>
        </flux:card>

        <flux:card class="bg-white p-5 shadow-sm">
            <flux:text>Institutions with courses</flux:text>
            <flux:heading size="xl" class="mt-2 text-3xl font-black">
                {{ $this->courses->pluck('institution_id')->unique()->count() }}
            </flux:heading>
        </flux:card>
    </div>

    @if ($formOpen)
        <flux:card class="bg-white p-5 shadow-sm sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading size="lg">
                        {{ $editingId ? 'Edit course' : 'Add a training course' }}
                    </flux:heading>
                    <flux:text class="mt-1">
                        Select the institution that will offer this course.
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
                        wire:model="institution_id"
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

                    <flux:input
                        wire:model="code"
                        label="Course code"
                        placeholder="e.g. BPP-NC-II"
                        required
                    />

                    <div class="md:col-span-2">
                        <flux:input
                            wire:model="name"
                            label="Course name"
                            placeholder="e.g. Bread and Pastry Production NC II"
                            required
                        />
                    </div>

                    <flux:select
                        wire:model="qualification_level"
                        label="Qualification level"
                        placeholder="Select qualification level"
                    >
                        <flux:select.option value="NC I">NC I</flux:select.option>
                        <flux:select.option value="NC II">NC II</flux:select.option>
                        <flux:select.option value="NC III">NC III</flux:select.option>
                        <flux:select.option value="NC IV">NC IV</flux:select.option>
                        <flux:select.option value="Other">Other</flux:select.option>
                    </flux:select>

                    <flux:input
                        wire:model="training_hours"
                        label="Training hours"
                        type="number"
                        min="1"
                        max="65535"
                        placeholder="e.g. 141"
                    />

                    <flux:select wire:model="is_active" label="Course status" required>
                        <flux:select.option value="1">Active</flux:select.option>
                        <flux:select.option value="0">Inactive</flux:select.option>
                    </flux:select>
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
                        {{ $editingId ? 'Save changes' : 'Save course' }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    <flux:card class="bg-white p-5 shadow-sm sm:p-6">
        <div class="mb-5">
            <flux:heading size="lg">Registered courses</flux:heading>
            <flux:text class="mt-1">
                Review courses, assigned institutions, and linked training batches.
            </flux:text>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[850px] text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-3">Course</th>
                        <th class="px-3 py-3">Institution</th>
                        <th class="px-3 py-3">Qualification</th>
                        <th class="px-3 py-3">Training hours</th>
                        <th class="px-3 py-3">Batches</th>
                        <th class="px-3 py-3">Status</th>
                        <th class="px-3 py-3 text-right">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->courses as $course)
                        <tr wire:key="course-row-{{ $course->id }}">
                            <td class="px-3 py-4">
                                <p class="font-bold text-slate-900">
                                    {{ $course->name }}
                                </p>
                                <p class="mt-1 text-xs font-semibold text-blue-700">
                                    {{ $course->code }}
                                </p>
                            </td>

                            <td class="px-3 py-4">
                                <p class="font-semibold text-slate-800">
                                    {{ $course->institution->name }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $course->institution->code }}
                                </p>
                            </td>

                            <td class="px-3 py-4 text-slate-600">
                                {{ $course->qualification_level ?: 'Not specified' }}
                            </td>

                            <td class="px-3 py-4 text-slate-600">
                                {{ $course->training_hours !== null ? number_format($course->training_hours).' hours' : 'Not specified' }}
                            </td>

                            <td class="px-3 py-4 text-slate-600">
                                {{ $course->batches_count }}
                            </td>

                            <td class="px-3 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $course->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $course->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>

                            <td class="px-3 py-4">
                                <div class="flex justify-end gap-2">
                                    <flux:button
                                        size="sm"
                                        variant="filled"
                                        wire:click="edit({{ $course->id }})"
                                    >
                                        Edit
                                    </flux:button>

                                    <flux:button
                                        size="sm"
                                        variant="{{ $course->is_active ? 'danger' : 'primary' }}"
                                        wire:click="toggleStatus({{ $course->id }})"
                                        wire:confirm="Change this course's active status?"
                                    >
                                        {{ $course->is_active ? 'Deactivate' : 'Activate' }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-12 text-center text-slate-500">
                                No courses have been registered yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
