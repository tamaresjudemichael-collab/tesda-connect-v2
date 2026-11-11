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
    'unitTasksModalOpen' => false,
    'activeUnitId' => null,
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
    'bulkTaskFormOpen' => false,
    'bulkTaskUnitId' => '',
    'bulkTaskRows' => [],
    'deleteConfirmOpen' => false,
    'deleteTargetType' => '',
    'deleteTargetId' => null,
    'deleteTargetName' => '',
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

$activeUnit = computed(function () {
    if (! filled($this->activeUnitId)) {
        return null;
    }

    return $this->units->firstWhere('id', (int) $this->activeUnitId);
});

$openUnitTasksModal = function (int $unitId): void {
    $unit = $this->findManagedUnit($unitId);
    $this->activeUnitId = $unit->id;
    $this->unitTasksModalOpen = true;
};

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
    $this->unitTasksModalOpen = false;
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
    $this->unitTasksModalOpen = false;
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
    $this->unitTasksModalOpen = false;
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
    $this->unitTasksModalOpen = false;
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

$openAddMultipleTasks = function (int $unitId): void {
    $this->unitTasksModalOpen = false;
    $unit = $this->findManagedUnit($unitId);
    $this->assertUnitStructureEditable($unit);

    $nextNumber = ((int) CompetencyTask::query()
        ->where('competency_unit_id', $unit->id)
        ->where('chart_type', 'progress')
        ->max('task_number')) + 1;

    $this->bulkTaskUnitId = (string) $unit->id;
    $this->bulkTaskRows = [[
        'id' => null,
        'chart_type' => 'progress',
        'task_number' => (string) $nextNumber,
        'name' => '',
        'sort_order' => (string) $nextNumber,
    ]];
    $this->resetValidation();
    $this->bulkTaskFormOpen = true;
};

$openEditAllTasks = function (int $unitId): void {
    $this->unitTasksModalOpen = false;
    $unit = $this->findManagedUnit($unitId);
    $this->assertUnitStructureEditable($unit);

    $this->bulkTaskUnitId = (string) $unit->id;
    $this->bulkTaskRows = $unit->tasks()
        ->orderBy('sort_order')
        ->orderBy('chart_type')
        ->orderBy('task_number')
        ->get()
        ->map(fn (CompetencyTask $task) => [
            'id' => $task->id,
            'chart_type' => $task->chart_type,
            'task_number' => (string) $task->task_number,
            'name' => $task->name,
            'sort_order' => (string) $task->sort_order,
        ])
        ->values()
        ->all();

    if (empty($this->bulkTaskRows)) {
        $this->bulkTaskRows = [[
            'id' => null,
            'chart_type' => 'progress',
            'task_number' => '1',
            'name' => '',
            'sort_order' => '1',
        ]];
    }

    $this->resetValidation();
    $this->bulkTaskFormOpen = true;
};

$addBulkTaskRow = function (): void {
    $unit = $this->findManagedUnit((int) $this->bulkTaskUnitId);
    $this->assertUnitStructureEditable($unit);

    $rows = $this->bulkTaskRows;
    $nextNumber = count($rows) + 1;
    $rows[] = [
        'id' => null,
        'chart_type' => 'progress',
        'task_number' => (string) $nextNumber,
        'name' => '',
        'sort_order' => (string) $nextNumber,
    ];
    $this->bulkTaskRows = $rows;
};

$removeBulkTaskRow = function (int $index): void {
    $rows = $this->bulkTaskRows;

    if (isset($rows[$index]) && empty($rows[$index]['id']) && count($rows) > 1) {
        unset($rows[$index]);
        $this->bulkTaskRows = array_values($rows);
    }
};

$saveBulkTasks = function (): void {
    $unit = $this->findManagedUnit((int) $this->bulkTaskUnitId);
    $this->assertUnitStructureEditable($unit);

    $validated = $this->validate([
        'bulkTaskRows' => ['required', 'array', 'min:1'],
        'bulkTaskRows.*.id' => ['nullable', 'integer'],
        'bulkTaskRows.*.chart_type' => ['required', Rule::in(['progress', 'achievement'])],
        'bulkTaskRows.*.task_number' => ['required', 'integer', 'min:1', 'max:65535'],
        'bulkTaskRows.*.name' => ['required', 'string', 'max:255'],
        'bulkTaskRows.*.sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
    ], [
        'bulkTaskRows.*.chart_type.in' => 'Choose Progress Chart or Achievement Chart for each task.',
        'bulkTaskRows.*.name.required' => 'Enter a name for every task row.',
    ]);

    $seen = [];
    $submittedIds = collect($validated['bulkTaskRows'])
        ->pluck('id')
        ->filter()
        ->map(fn ($id) => (int) $id);
    if ($submittedIds->count() !== $submittedIds->unique()->count()) {
        throw ValidationException::withMessages([
            'bulkTaskRows' => 'A task cannot appear more than once in the bulk editor.',
        ]);
    }

    foreach ($validated['bulkTaskRows'] as $index => $row) {
        $key = $row['chart_type'].'|'.$row['task_number'];
        if (isset($seen[$key])) {
            throw ValidationException::withMessages([
                "bulkTaskRows.{$index}.task_number" => 'Task numbers must be unique within the same chart type and unit.',
            ]);
        }
        $seen[$key] = true;

        if (filled($row['id'] ?? null)) {
            $existing = $this->findManagedTask((int) $row['id']);
            if ((int) $existing->competency_unit_id !== (int) $unit->id) {
                abort(422, 'A task row does not belong to the selected competency unit.');
            }
            if ($existing->progressRecords()->exists()) {
                throw ValidationException::withMessages([
                    "bulkTaskRows.{$index}.name" => 'This task is protected because trainee progress records already exist.',
                ]);
            }
        }

        $duplicate = CompetencyTask::query()
            ->where('competency_unit_id', $unit->id)
            ->where('chart_type', $row['chart_type'])
            ->where('task_number', $row['task_number'])
            ->when($submittedIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $submittedIds->all()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                "bulkTaskRows.{$index}.task_number" => 'That task number already exists for this unit and chart.',
            ]);
        }
    }

    $course = $this->findManagedCourse((int) $unit->course_id);
    DB::transaction(function () use ($validated, $unit, $course): void {
        foreach ($validated['bulkTaskRows'] as $row) {
            $attributes = [
                'competency_unit_id' => $unit->id,
                'chart_type' => $row['chart_type'],
                'task_number' => (int) $row['task_number'],
                'name' => trim($row['name']),
                'sort_order' => (int) $row['sort_order'],
            ];

            if (filled($row['id'] ?? null)) {
                $task = CompetencyTask::query()->where('competency_unit_id', $unit->id)->findOrFail($row['id']);
                $task->update($attributes);
                $event = 'competency_task_updated';
                $message = 'Updated competency task '.$task->name;
            } else {
                $task = CompetencyTask::create($attributes);
                $event = 'competency_task_created';
                $message = 'Created competency task '.$task->name;
            }

            ActivityLogger::record($event, $message, (int) $course->institution_id, [
                'course_id' => $course->id,
                'competency_unit_id' => $unit->id,
                'competency_task_id' => $task->id,
                'chart_type' => $task->chart_type,
                'bulk_update' => true,
            ]);
        }
    });

    unset($this->units);
    $this->bulkTaskFormOpen = false;
    $this->bulkTaskUnitId = '';
    $this->bulkTaskRows = [];
    $this->resetValidation();
    Flux::toast(variant: 'success', text: 'All task changes have been saved.');
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

$openDeleteUnit = function (int $unitId): void {
    $this->unitTasksModalOpen = false;
    $unit = $this->findManagedUnit($unitId);
    $this->assertUnitStructureEditable($unit);

    $this->deleteTargetType = 'unit';
    $this->deleteTargetId = $unit->id;
    $this->deleteTargetName = 'Unit '.$unit->unit_number.' — '.$unit->name;
    $this->deleteConfirmOpen = true;
};

$openDeleteTask = function (int $taskId): void {
    $this->unitTasksModalOpen = false;
    $task = $this->findManagedTask($taskId);
    $unit = $this->findManagedUnit((int) $task->competency_unit_id);
    $this->assertUnitStructureEditable($unit);

    if ($task->progressRecords()->exists()) {
        throw ValidationException::withMessages([
            'task' => 'This task cannot be deleted because trainee progress records already exist.',
        ]);
    }

    $this->deleteTargetType = 'task';
    $this->deleteTargetId = $task->id;
    $this->deleteTargetName = $task->name;
    $this->deleteConfirmOpen = true;
};

$cancelDelete = function (): void {
    $this->reset('deleteTargetType', 'deleteTargetId', 'deleteTargetName');
    $this->deleteConfirmOpen = false;
};

$confirmDelete = function (): void {
    abort_unless(in_array($this->deleteTargetType, ['unit', 'task'], true) && filled($this->deleteTargetId), 422);

    if ($this->deleteTargetType === 'unit') {
        $this->deleteUnit((int) $this->deleteTargetId);
    } else {
        $this->deleteTask((int) $this->deleteTargetId);
    }

    $this->cancelDelete();
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

    <flux:modal name="competency-unit-form" wire:model="unitFormOpen" class="w-full max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingUnitId ? 'Edit competency unit' : 'Add competency unit' }}</flux:heading>
                <flux:text class="mt-1">Set the unit number, official competency unit name, and display order.</flux:text>
            </div>

            <form wire:submit="saveUnit" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <flux:input id="unit-number" type="number" min="1" max="65535" wire:model="unitNumber" label="Unit number" required />
                        @error('unitNumber')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <flux:input id="unit-name" type="text" maxlength="150" wire:model="unitName" label="Unit name" placeholder="e.g. Raise Organic Chicken" required />
                        @error('unitName')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <flux:input id="unit-sort-order" type="number" min="0" max="65535" wire:model="unitSortOrder" label="Display order" required />
                        @error('unitSortOrder')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:button variant="ghost" type="button" wire:click="resetUnitForm">Cancel</flux:button>
                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="saveUnit">
                        {{ $editingUnitId ? 'Save changes' : 'Save competency unit' }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal name="competency-task-form" wire:model="taskFormOpen" class="w-full max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingTaskId ? 'Edit competency task' : 'Add competency task' }}</flux:heading>
                <flux:text class="mt-1">Configure the task under the selected competency unit and chart.</flux:text>
            </div>

            <form wire:submit="saveTask" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <flux:select id="task-unit" wire:model="taskUnitId" label="Competency unit" required>
                            @forelse ($this->units as $unitOption)
                                <flux:select.option :value="$unitOption->id">Unit {{ $unitOption->unit_number }} — {{ $unitOption->name }}</flux:select.option>
                            @empty
                                <flux:select.option value="">No units available</flux:select.option>
                            @endforelse
                        </flux:select>
                        @error('taskUnitId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <flux:select id="task-chart-type" wire:model="taskChartType" label="Chart" required>
                            <flux:select.option value="progress">Progress Chart</flux:select.option>
                            <flux:select.option value="achievement">Achievement Chart</flux:select.option>
                        </flux:select>
                        @error('taskChartType')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <flux:input id="task-number" type="number" min="1" max="65535" wire:model="taskNumber" label="Task number" required />
                        @error('taskNumber')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <flux:input id="task-sort-order" type="number" min="0" max="65535" wire:model="taskSortOrder" label="Display order" required />
                        @error('taskSortOrder')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <flux:input id="task-name" type="text" maxlength="255" wire:model="taskName" label="Task name" placeholder="Enter the task as it appears on the official chart" required />
                        @error('taskName')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                @error('task')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
                <div class="flex flex-wrap justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:button variant="ghost" type="button" wire:click="resetTaskForm">Cancel</flux:button>
                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="saveTask">
                        {{ $editingTaskId ? 'Save changes' : 'Save task' }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal name="competency-bulk-task-form" wire:model="bulkTaskFormOpen" class="w-full max-w-5xl">
        <div class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ collect($bulkTaskRows)->contains(fn ($row) => filled($row['id'] ?? null)) ? 'Edit all competency tasks' : 'Add multiple competency tasks' }}</flux:heading>
                    <flux:text class="mt-1">Add or update multiple tasks in one save. Choose the chart type separately for every task.</flux:text>
                </div>
                <flux:button type="button" variant="outline" icon="plus" wire:click="addBulkTaskRow">Add another task</flux:button>
            </div>

            @error('bulkTaskRows')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
            <div class="max-h-[65vh] space-y-3 overflow-y-auto pr-1">
                @foreach ($bulkTaskRows as $index => $row)
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="bulk-task-row-{{ $row['id'] ?? 'new-'.$index }}">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">Task row {{ $index + 1 }} @if(filled($row['id'] ?? null))<span class="ml-1 text-xs font-normal text-zinc-500">Existing task</span>@else<span class="ml-1 text-xs font-normal text-emerald-600">New task</span>@endif</p>
                            @if (blank($row['id'] ?? null) && count($bulkTaskRows) > 1)
                                <flux:button type="button" size="xs" variant="ghost" color="danger" icon="x-mark" wire:click="removeBulkTaskRow({{ $index }})">Remove row</flux:button>
                            @endif
                        </div>
                        <div class="grid gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-3">
                                <flux:select wire:model="bulkTaskRows.{{ $index }}.chart_type" label="Chart" required>
                                    <flux:select.option value="progress">Progress Chart</flux:select.option>
                                    <flux:select.option value="achievement">Achievement Chart</flux:select.option>
                                </flux:select>
                                @error("bulkTaskRows.{$index}.chart_type")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="sm:col-span-2">
                                <flux:input type="number" min="1" max="65535" wire:model="bulkTaskRows.{{ $index }}.task_number" label="Task no." required />
                                @error("bulkTaskRows.{$index}.task_number")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="sm:col-span-5">
                                <flux:input type="text" maxlength="255" wire:model="bulkTaskRows.{{ $index }}.name" label="Task name" placeholder="Enter competency task" required />
                                @error("bulkTaskRows.{$index}.name")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="sm:col-span-2">
                                <flux:input type="number" min="0" max="65535" wire:model="bulkTaskRows.{{ $index }}.sort_order" label="Display order" required />
                                @error("bulkTaskRows.{$index}.sort_order")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button variant="ghost" type="button" wire:click="$set('bulkTaskFormOpen', false)">Cancel</flux:button>
                <flux:button variant="primary" type="button" wire:click="saveBulkTasks" wire:loading.attr="disabled" wire:target="saveBulkTasks">
                    Save all {{ count($bulkTaskRows) }} task(s)
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="competency-delete-confirm" wire:model="deleteConfirmOpen" class="w-full max-w-md">
        <div class="space-y-5">
            <div class="flex items-start gap-3">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-rose-100 text-sm font-black text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">
                    !
                </div>
                <div class="min-w-0 space-y-1">
                    <flux:heading size="lg">Delete {{ $deleteTargetType === 'unit' ? 'competency unit' : 'competency task' }}?</flux:heading>
                    <flux:text>This action cannot be undone.</flux:text>
                    <p class="break-words pt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $deleteTargetName }}</p>
                    @error('unit')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
                    @error('task')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button variant="ghost" type="button" wire:click="cancelDelete">Cancel</flux:button>
                <flux:button variant="danger" type="button" wire:click="confirmDelete" wire:loading.attr="disabled" wire:target="confirmDelete">
                    Delete {{ $deleteTargetType === 'unit' ? 'unit' : 'task' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    @if (filled($selectedCourseId))
        <section class="space-y-4">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-black text-slate-900">Competency units and tasks</h2>
                    <p class="mt-1 text-sm text-slate-500">Tasks are kept separate by chart type because the Progress and Achievement charts may have different wording.</p>
                </div>
            </div>

            @if ($this->units->isNotEmpty())
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($this->units as $unit)
                        @php
                            $unitLocked = $unit->reviews_count > 0 || $unit->tasks->contains(fn ($task) => $task->progress_records_count > 0);
                            $progressCount = $unit->tasks->where('chart_type', 'progress')->count();
                            $achievementCount = $unit->tasks->where('chart_type', 'achievement')->count();
                        @endphp
                        <article wire:key="competency-setup-unit-card-{{ $unit->id }}" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Competency Unit {{ $unit->unit_number }}</p>
                                    <h3 class="mt-1 text-lg font-bold text-zinc-900 dark:text-zinc-100">{{ $unit->name }}</h3>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Display order {{ $unit->sort_order }} · {{ $unit->tasks->count() }} total task(s)</p>
                                </div>
                                @if ($unitLocked)
                                    <flux:badge color="amber" size="sm">Protected</flux:badge>
                                @else
                                    <flux:badge color="green" size="sm">Editable</flux:badge>
                                @endif
                            </div>
                            <div class="mt-4 grid grid-cols-2 gap-3">
                                <div class="rounded-xl bg-sky-50 p-3 dark:bg-sky-950/30">
                                    <p class="text-xs font-semibold text-sky-800 dark:text-sky-200">Progress Chart</p>
                                    <p class="mt-1 text-2xl font-black text-sky-900 dark:text-sky-100">{{ $progressCount }}</p>
                                </div>
                                <div class="rounded-xl bg-violet-50 p-3 dark:bg-violet-950/30">
                                    <p class="text-xs font-semibold text-violet-800 dark:text-violet-200">Achievement Chart</p>
                                    <p class="mt-1 text-2xl font-black text-violet-900 dark:text-violet-100">{{ $achievementCount }}</p>
                                </div>
                            </div>
                            <div class="mt-4 flex flex-wrap justify-end gap-2">
                                <flux:button size="sm" variant="primary" wire:click="openUnitTasksModal({{ $unit->id }})">Manage unit tasks</flux:button>
                                @unless ($unitLocked)
                                    <flux:button size="sm" variant="outline" icon="pencil" wire:click="editUnit({{ $unit->id }})">Edit unit</flux:button>
                                    <flux:button size="sm" variant="danger" icon="trash" wire:click="openDeleteUnit({{ $unit->id }})">Delete unit</flux:button>
                                @endunless
                            </div>
                        </article>
                    @endforeach
                </div>

                <flux:modal name="competency-unit-tasks-manager" wire:model="unitTasksModalOpen" class="w-full max-w-5xl">
                    @if ($this->activeUnit)
                        @php
                            $unit = $this->activeUnit;
                            $unitLocked = $unit->reviews_count > 0 || $unit->tasks->contains(fn ($task) => $task->progress_records_count > 0);
                        @endphp
                        <div class="space-y-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <flux:heading size="lg">Unit {{ $unit->unit_number }} — {{ $unit->name }}</flux:heading>
                                    <flux:subheading>Manage tasks by chart type. Progress Chart and Achievement Chart are shown separately.</flux:subheading>
                                </div>
                                @if ($unitLocked)<flux:badge color="amber">Protected</flux:badge>@endif
                            </div>
                            @if ($unitLocked)
                                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">This unit is protected because trainee progress or unit reviews already exist. Its task structure cannot be changed.</div>
                            @else
                                <div class="flex flex-wrap justify-end gap-2">
                                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openAddMultipleTasks({{ $unit->id }})">Add multiple tasks</flux:button>
                                    @if ($unit->tasks->isNotEmpty())
                                        <flux:button size="sm" variant="outline" icon="pencil-square" wire:click="openEditAllTasks({{ $unit->id }})">Edit all tasks</flux:button>
                                    @endif
                                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="openCreateTask({{ $unit->id }})">Add single task</flux:button>
                                </div>
                            @endif
                            <div class="grid gap-4 lg:grid-cols-2">
                                @foreach (['progress' => 'Progress Chart', 'achievement' => 'Achievement Chart'] as $chartKey => $chartLabel)
                                    <section class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                                        <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                                            <h4 class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $chartLabel }}</h4>
                                            <p class="text-xs text-zinc-500">{{ $unit->tasks->where('chart_type', $chartKey)->count() }} task(s)</p>
                                        </div>
                                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                            @forelse ($unit->tasks->where('chart_type', $chartKey) as $task)
                                                <div class="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between" wire:key="competency-setup-task-{{ $task->id }}">
                                                    <div class="min-w-0">
                                                        <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $task->task_number }}. {{ $task->name }}</p>
                                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Display order {{ $task->sort_order }} · {{ $task->progress_records_count }} trainee progress record(s)</p>
                                                    </div>
                                                    @unless ($unitLocked || $task->progress_records_count > 0)
                                                        <div class="flex shrink-0 gap-2">
                                                            <flux:button size="xs" variant="outline" icon="pencil" wire:click="editTask({{ $task->id }})">Edit</flux:button>
                                                            <flux:button size="xs" variant="danger" icon="trash" wire:click="openDeleteTask({{ $task->id }})">Delete</flux:button>
                                                        </div>
                                                    @endunless
                                                </div>
                                            @empty
                                                <div class="p-4 text-sm text-zinc-500 dark:text-zinc-400">No tasks configured for this chart.</div>
                                            @endforelse
                                        </div>
                                    </section>
                                @endforeach
                            </div>
                            <div class="flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <flux:button type="button" variant="ghost" wire:click="$set('unitTasksModalOpen', false)">Close</flux:button>
                            </div>
                        </div>
                    @endif
                </flux:modal>
            @else
                <div class="rounded-2xl border border-dashed border-zinc-300 bg-white p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="font-semibold text-zinc-800 dark:text-zinc-100">No competency units configured</h3>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Add the units from the official Progress and Achievement Chart for this course.</p>
                </div>
            @endif
        </section>
    @else
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">Select a course to view and manage its competency setup.</div>
    @endif
</div>
