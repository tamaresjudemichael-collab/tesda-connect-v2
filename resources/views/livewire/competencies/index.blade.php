<?php

use App\Models\CompetencyProgress;
use App\Models\CompetencyTask;
use App\Models\CompetencyUnit;
use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Competency Setup');

state([
    'selectedCourseId' => '',
    'unitFormOpen' => false,
    'editingUnitId' => null,
    'unitNumber' => '',
    'unitName' => '',
    'unitSortOrder' => '0',
    'taskFormOpen' => false,
    'editingTaskId' => null,
    'taskUnitId' => '',
    'taskChartType' => 'progress',
    'taskNumber' => '',
    'taskName' => '',
    'taskSortOrder' => '0',
]);

$courses = computed(function () {
    $user = auth()->user();
    abort_unless($user && $user->hasRole('administrator', 'focal_person'), 403);

    return Course::query()
        ->with('institution')
        ->whereIn('institution_id', InstitutionScope::idsFor($user))
        ->orderBy('name')
        ->get();
});

$units = computed(function () {
    if (! filled($this->selectedCourseId)) {
        return collect();
    }

    $course = $this->findManagedCourse((int) $this->selectedCourseId);

    return CompetencyUnit::query()
        ->where('course_id', $course->id)
        ->withCount('reviews')
        ->with(['tasks' => fn ($query) => $query->withCount('progressRecords')])
        ->orderBy('sort_order')
        ->orderBy('unit_number')
        ->get();
});

$findManagedCourse = function (int $courseId): Course {
    $user = auth()->user();
    abort_unless($user && $user->hasRole('administrator', 'focal_person'), 403);

    return Course::query()
        ->whereIn('institution_id', InstitutionScope::idsFor($user))
        ->findOrFail($courseId);
};

$findManagedUnit = function (int $unitId): CompetencyUnit {
    $unit = CompetencyUnit::query()->findOrFail($unitId);
    $this->findManagedCourse((int) $unit->course_id);

    return $unit;
};

$findManagedTask = function (int $taskId): CompetencyTask {
    $task = CompetencyTask::query()->findOrFail($taskId);
    $this->findManagedUnit((int) $task->competency_unit_id);

    return $task;
};

$resetUnitForm = function (): void {
    $this->reset('editingUnitId', 'unitNumber', 'unitName', 'unitSortOrder');
    $this->unitSortOrder = '0';
    $this->unitFormOpen = false;
    $this->resetValidation();
};

$openCreateUnit = function (): void {
    abort_unless(filled($this->selectedCourseId), 422, 'Select a course first.');
    $this->findManagedCourse((int) $this->selectedCourseId);
    $this->resetUnitForm();
    $this->unitNumber = (string) (((int) CompetencyUnit::query()
        ->where('course_id', $this->selectedCourseId)
        ->max('unit_number')) + 1);
    $this->unitSortOrder = $this->unitNumber;
    $this->unitFormOpen = true;
};

$editUnit = function (int $unitId): void {
    $unit = $this->findManagedUnit($unitId);
    $this->assertUnitStructureEditable($unit);

    $this->resetValidation();
    $this->selectedCourseId = (string) $unit->course_id;
    $this->editingUnitId = $unit->id;
    $this->unitNumber = (string) $unit->unit_number;
    $this->unitName = $unit->name;
    $this->unitSortOrder = (string) $unit->sort_order;
    $this->unitFormOpen = true;
};

$assertUnitStructureEditable = function (CompetencyUnit $unit): void {
    $hasProgress = $unit->tasks()->whereHas('progressRecords')->exists();
    $hasReviews = $unit->reviews()->exists();

    if ($hasProgress || $hasReviews) {
        throw ValidationException::withMessages([
            'unit' => 'This unit is protected because trainee progress or unit reviews already exist. Create a new unit for curriculum changes.',
        ]);
    }
};

$saveUnit = function (): void {
    $courseId = filled($this->editingUnitId)
        ? (int) $this->findManagedUnit((int) $this->editingUnitId)->course_id
        : (int) $this->selectedCourseId;
    $course = $this->findManagedCourse($courseId);
    $unit = filled($this->editingUnitId) ? $this->findManagedUnit((int) $this->editingUnitId) : null;

    if ($unit) {
        $this->assertUnitStructureEditable($unit);
    }

    $validated = $this->validate([
        'unitNumber' => [
            'required', 'integer', 'min:1', 'max:65535',
            Rule::unique('competency_units', 'unit_number')
                ->where('course_id', $course->id)
                ->ignore($unit?->id),
        ],
        'unitName' => ['required', 'string', 'max:150'],
        'unitSortOrder' => ['required', 'integer', 'min:0', 'max:65535'],
    ], [
        'unitNumber.unique' => 'That unit number already exists for this course.',
        'unitName.required' => 'Enter a name for the competency unit.',
    ]);

    $attributes = [
        'course_id' => $course->id,
        'unit_number' => (int) $validated['unitNumber'],
        'name' => trim($validated['unitName']),
        'sort_order' => (int) $validated['unitSortOrder'],
    ];

    if ($unit) {
        $unit->update($attributes);
        $event = 'competency_unit_updated';
        $message = 'Updated competency unit '.$unit->name;
    } else {
        $unit = CompetencyUnit::create($attributes);
        $event = 'competency_unit_created';
        $message = 'Created competency unit '.$unit->name;
    }

    ActivityLogger::record($event, $message, (int) $course->institution_id, [
        'course_id' => $course->id,
        'competency_unit_id' => $unit->id,
        'unit_number' => $unit->unit_number,
    ]);

    unset($this->units);
    $this->resetUnitForm();
    Flux::toast(variant: 'success', text: $message.'.');
};

$deleteUnit = function (int $unitId): void {
    $unit = $this->findManagedUnit($unitId);
    $this->assertUnitStructureEditable($unit);
    $course = $this->findManagedCourse((int) $unit->course_id);

    DB::transaction(function () use ($unit): void {
        $unit->tasks()->delete();
        $unit->delete();
    });

    ActivityLogger::record('competency_unit_deleted', 'Deleted competency unit '.$unit->name, (int) $course->institution_id, [
        'course_id' => $course->id,
        'competency_unit_id' => $unitId,
    ]);

    unset($this->units);
    Flux::toast(variant: 'success', text: 'Competency unit deleted.');
};

$resetTaskForm = function (): void {
    $this->reset('editingTaskId', 'taskUnitId', 'taskChartType', 'taskNumber', 'taskName', 'taskSortOrder');
    $this->taskChartType = 'progress';
    $this->taskSortOrder = '0';
    $this->taskFormOpen = false;
    $this->resetValidation();
};

$openCreateTask = function (int $unitId): void {
    $unit = $this->findManagedUnit($unitId);
    $this->assertUnitStructureEditable($unit);
    $this->resetTaskForm();
    $this->taskUnitId = (string) $unit->id;
    $this->taskNumber = (string) (((int) CompetencyTask::query()
        ->where('competency_unit_id', $unit->id)
        ->where('chart_type', 'progress')
        ->max('task_number')) + 1);
    $this->taskSortOrder = $this->taskNumber;
    $this->taskFormOpen = true;
};

$editTask = function (int $taskId): void {
    $task = $this->findManagedTask($taskId);
    $unit = $this->findManagedUnit((int) $task->competency_unit_id);
    $this->assertUnitStructureEditable($unit);

    $this->resetValidation();
    $this->selectedCourseId = (string) $unit->course_id;
    $this->editingTaskId = $task->id;
    $this->taskUnitId = (string) $unit->id;
    $this->taskChartType = $task->chart_type;
    $this->taskNumber = (string) $task->task_number;
    $this->taskName = $task->name;
    $this->taskSortOrder = (string) $task->sort_order;
    $this->taskFormOpen = true;
};

$saveTask = function (): void {
    $unit = $this->findManagedUnit((int) $this->taskUnitId);
    $this->assertUnitStructureEditable($unit);
    $task = filled($this->editingTaskId) ? $this->findManagedTask((int) $this->editingTaskId) : null;

    if ($task) {
        // Protect the original unit too. Otherwise a forged taskUnitId could move a
        // task away from a unit that already has progress or a review.
        $originalUnit = $this->findManagedUnit((int) $task->competency_unit_id);
        $this->assertUnitStructureEditable($originalUnit);
    }

    $validated = $this->validate([
        'taskUnitId' => ['required', 'integer', Rule::in([(int) $unit->id])],
        'taskChartType' => ['required', Rule::in(['progress', 'achievement'])],
        'taskNumber' => [
            'required', 'integer', 'min:1', 'max:65535',
            Rule::unique('competency_tasks', 'task_number')
                ->where('competency_unit_id', $unit->id)
                ->where('chart_type', $this->taskChartType)
                ->ignore($task?->id),
        ],
        'taskName' => ['required', 'string', 'max:255'],
        'taskSortOrder' => ['required', 'integer', 'min:0', 'max:65535'],
    ], [
        'taskNumber.unique' => 'That task number already exists for this unit and chart.',
        'taskChartType.in' => 'Choose Progress Chart or Achievement Chart.',
    ]);

    $attributes = [
        'competency_unit_id' => $unit->id,
        'chart_type' => $validated['taskChartType'],
        'task_number' => (int) $validated['taskNumber'],
        'name' => trim($validated['taskName']),
        'sort_order' => (int) $validated['taskSortOrder'],
    ];

    if ($task) {
        $task->update($attributes);
        $event = 'competency_task_updated';
        $message = 'Updated competency task '.$task->name;
    } else {
        $task = CompetencyTask::create($attributes);
        $event = 'competency_task_created';
        $message = 'Created competency task '.$task->name;
    }

    $course = $this->findManagedCourse((int) $unit->course_id);
    ActivityLogger::record($event, $message, (int) $course->institution_id, [
        'course_id' => $course->id,
        'competency_unit_id' => $unit->id,
        'competency_task_id' => $task->id,
        'chart_type' => $task->chart_type,
    ]);

    unset($this->units);
    $this->resetTaskForm();
    Flux::toast(variant: 'success', text: $message.'.');
};

$deleteTask = function (int $taskId): void {
    $task = $this->findManagedTask($taskId);
    $unit = $this->findManagedUnit((int) $task->competency_unit_id);
    $this->assertUnitStructureEditable($unit);

    if ($task->progressRecords()->exists()) {
        throw ValidationException::withMessages([
            'task' => 'This task cannot be deleted because trainee progress records already exist.',
        ]);
    }

    $course = $this->findManagedCourse((int) $unit->course_id);
    $taskName = $task->name;
    $task->delete();

    ActivityLogger::record('competency_task_deleted', 'Deleted competency task '.$taskName, (int) $course->institution_id, [
        'course_id' => $course->id,
        'competency_unit_id' => $unit->id,
        'competency_task_id' => $taskId,
    ]);

    unset($this->units);
    Flux::toast(variant: 'success', text: 'Competency task deleted.');
};
?>

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <section class="rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-900 p-6 text-white shadow-xl sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-300">Training Operations</p>
        <h1 class="mt-3 text-3xl font-black">Competency Setup Management</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-blue-100">Manage the Progress and Achievement Chart tasks attached to each course. Units or tasks connected to trainee progress or unit reviews are protected against structural changes.</p>
    </section>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>
    @endif
    @error('unit')
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div>
    @enderror
    @error('task')
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div>
    @enderror

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <label for="competency-course" class="mb-2 block text-sm font-bold text-slate-800">Select course</label>
        <select id="competency-course" wire:model.live="selectedCourseId" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
            <option value="">Choose a course...</option>
            @forelse ($this->courses as $course)
                <option value="{{ $course->id }}">{{ $course->name }} — {{ $course->institution?->name }} ({{ $course->code }})</option>
            @empty
                <option value="" disabled>No courses are available in your institution scope.</option>
            @endforelse
        </select>
        @error('selectedCourseId')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
        @if (filled($selectedCourseId))
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-500">Manage unit definitions and tasks for the selected course.</p>
                <button type="button" wire:click="openCreateUnit" class="rounded-xl bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800">+ Add competency unit</button>
            </div>
        @endif
    </section>

    @if ($unitFormOpen)
        <section class="rounded-2xl border border-blue-200 bg-blue-50/50 p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-black text-slate-900">{{ $editingUnitId ? 'Edit competency unit' : 'Add competency unit' }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div>
                    <label for="unit-number" class="mb-1 block text-xs font-bold text-slate-700">Unit number</label>
                    <input id="unit-number" type="number" min="1" max="65535" wire:model="unitNumber" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    @error('unitNumber')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-2">
                    <label for="unit-name" class="mb-1 block text-xs font-bold text-slate-700">Unit name</label>
                    <input id="unit-name" type="text" maxlength="150" wire:model="unitName" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm" placeholder="e.g. Raise Organic Chicken">
                    @error('unitName')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="unit-sort-order" class="mb-1 block text-xs font-bold text-slate-700">Display order</label>
                    <input id="unit-sort-order" type="number" min="0" max="65535" wire:model="unitSortOrder" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    @error('unitSortOrder')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="saveUnit" class="rounded-xl bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800">Save unit</button>
                <button type="button" wire:click="resetUnitForm" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700">Cancel</button>
            </div>
        </section>
    @endif

    @if ($taskFormOpen)
        <section class="rounded-2xl border border-cyan-200 bg-cyan-50/50 p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-black text-slate-900">{{ $editingTaskId ? 'Edit competency task' : 'Add competency task' }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <label for="task-unit" class="mb-1 block text-xs font-bold text-slate-700">Competency unit</label>
                    <select id="task-unit" wire:model="taskUnitId" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                        @forelse ($this->units as $unitOption)
                            <option value="{{ $unitOption->id }}">Unit {{ $unitOption->unit_number }} — {{ $unitOption->name }}</option>
                        @empty
                            <option value="">No units available</option>
                        @endforelse
                    </select>
                    @error('taskUnitId')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="task-chart-type" class="mb-1 block text-xs font-bold text-slate-700">Chart</label>
                    <select id="task-chart-type" wire:model="taskChartType" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                        <option value="progress">Progress Chart</option>
                        <option value="achievement">Achievement Chart</option>
                    </select>
                    @error('taskChartType')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="task-number" class="mb-1 block text-xs font-bold text-slate-700">Task number</label>
                    <input id="task-number" type="number" min="1" max="65535" wire:model="taskNumber" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    @error('taskNumber')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="task-sort-order" class="mb-1 block text-xs font-bold text-slate-700">Display order</label>
                    <input id="task-sort-order" type="number" min="0" max="65535" wire:model="taskSortOrder" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    @error('taskSortOrder')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-2">
                    <label for="task-name" class="mb-1 block text-xs font-bold text-slate-700">Task name</label>
                    <input id="task-name" type="text" maxlength="255" wire:model="taskName" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm" placeholder="Enter the task as it appears on the official chart">
                    @error('taskName')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
            </div>
            @error('task')<p class="mt-3 text-sm text-rose-700">{{ $message }}</p>@enderror
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="saveTask" class="rounded-xl bg-cyan-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-cyan-900">Save task</button>
                <button type="button" wire:click="resetTaskForm" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700">Cancel</button>
            </div>
        </section>
    @endif

    @if (filled($selectedCourseId))
        <section class="space-y-4">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-black text-slate-900">Competency units and tasks</h2>
                    <p class="mt-1 text-sm text-slate-500">Tasks are kept separate by chart type because the Progress and Achievement charts may have different wording.</p>
                </div>
            </div>

            @forelse ($this->units as $unit)
                @php
                    $unitLocked = $unit->reviews_count > 0 || $unit->tasks->contains(fn ($task) => $task->progress_records_count > 0);
                @endphp
                <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" wire:key="competency-setup-unit-{{ $unit->id }}">
                    <div class="flex flex-col gap-3 border-b border-slate-100 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                        <div>
                            <h3 class="font-black text-slate-900">Unit {{ $unit->unit_number }} — {{ $unit->name }}</h3>
                            <p class="mt-1 text-xs text-slate-500">{{ $unit->tasks->count() }} task(s) · Display order {{ $unit->sort_order }}</p>
                            @if ($unitLocked)
                                <p class="mt-1 text-xs font-semibold text-amber-700">Structure protected: trainee progress or unit review exists.</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @unless ($unitLocked)
                                <button type="button" wire:click="openCreateTask({{ $unit->id }})" class="rounded-lg bg-cyan-800 px-3 py-2 text-xs font-bold text-white hover:bg-cyan-900">+ Add task</button>
                                <button type="button" wire:click="editUnit({{ $unit->id }})" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700">Edit unit</button>
                                <button type="button" wire:click="deleteUnit({{ $unit->id }})" wire:confirm="Delete this unit and its tasks? This is allowed only when there are no progress records or unit reviews." class="rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700">Delete unit</button>
                            @endunless
                        </div>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @forelse ($unit->tasks as $task)
                            <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="competency-setup-task-{{ $task->id }}">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-800">{{ $task->task_number }}. {{ $task->name }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $task->chart_type === 'progress' ? 'Progress Chart' : 'Achievement Chart' }} · Sort order {{ $task->sort_order }} · {{ $task->progress_records_count }} trainee progress record(s)</p>
                                </div>
                                @unless ($unitLocked || $task->progress_records_count > 0)
                                    <div class="flex gap-2">
                                        <button type="button" wire:click="editTask({{ $task->id }})" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700">Edit</button>
                                        <button type="button" wire:click="deleteTask({{ $task->id }})" wire:confirm="Delete this competency task?" class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700">Delete</button>
                                    </div>
                                @endunless
                            </div>
                        @empty
                            <div class="p-5 text-sm text-slate-500">No tasks are defined for this unit yet.</div>
                        @endforelse
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h3 class="font-bold text-slate-800">No competency units configured</h3>
                    <p class="mt-1 text-sm text-slate-500">Add the units from the official Progress and Achievement Chart for this course.</p>
                </div>
            @endforelse
        </section>
    @else
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">Select a course to view and manage its competency setup.</div>
    @endif
</div>
