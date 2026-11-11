<?php

use App\Models\CompetencyProgress;
use App\Models\CompetencyReportSubmission;
use App\Models\CompetencyTask;
use App\Models\CompetencyUnit;
use App\Models\CompetencyUnitReview;
use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Support\ActivityLogger;
use App\Support\CompetencyReportWorkflow;
use App\Support\InstitutionScope;
use Illuminate\Support\Facades\DB;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Competency Progress & Achievement');

state([
    'courseId' => null,
    'enrollmentId' => null,
    'chartType' => 'progress',
    'unitFilter' => 'all',
    'statusFilter' => 'all',
    'selectedTaskIds' => [],
    'bulkStatus' => '',
    'selectedUnitId' => null,
    'unitModalOpen' => false,
    'verifyModalOpen' => false,
    'verifyUnitId' => null,
    'unitReviewMessage' => '',
    'unitReviewMessageType' => 'error',
    'unitReviewResultOpen' => false,
    'submissionReference' => '',
    'submissionRemarks' => '',
    'activeTab' => 'dashboard',
]);

$courses = computed(function () {
    $user = auth()->user();

    if (! $user) {
        return collect();
    }

    $query = Course::query()
        ->with('institution')
        ->where('is_active', true);

    if (! $user->hasRole('administrator')) {
        $query->whereIn('institution_id', InstitutionScope::idsFor($user));
    }

    return $query->orderBy('name')->get();
});

$authorizedCourse = computed(function () {
    $user = auth()->user();

    if (! $user || ! $this->courseId) {
        return null;
    }

    $query = Course::query()
        ->whereKey((int) $this->courseId)
        ->where('is_active', true);

    if (! $user->hasRole('administrator')) {
        $query->whereIn('institution_id', InstitutionScope::idsFor($user));
    }

    return $query->first();
});

$courseUnits = computed(function () {
    $course = $this->authorizedCourse;

    if (! $course) {
        return collect();
    }

    return CompetencyUnit::query()
        ->where('course_id', $course->id)
        ->orderBy('sort_order')
        ->get();
});

$applications = computed(function () {
    $course = $this->authorizedCourse;
    $user = auth()->user();

    if (! $course || ! $user) {
        return collect();
    }

    $query = EnrollmentApplication::query()
        ->with(['institution', 'course', 'trainingBatch'])
        ->where('course_id', $course->id)
        ->where('application_status', 'confirmed');

    if (! $user->hasRole('administrator')) {
        $query->whereIn('institution_id', InstitutionScope::idsFor($user));
    }

    return $query->orderBy('learner_name')->get();
});

$selectedEnrollment = computed(function () {
    if (! $this->enrollmentId) {
        return null;
    }

    return $this->applications->firstWhere(
        'id',
        (int) $this->enrollmentId
    );
});

$reportSubmission = computed(function () {
    if (! $this->selectedEnrollment) {
        return null;
    }

    return $this->selectedEnrollment
        ->competencyReportSubmission()
        ->with(['reviewer', 'finalizer', 'submitter'])
        ->first();
});
$units = computed(function () {
    $course = $this->authorizedCourse;

    if (! $course) {
        return collect();
    }

    $enrollment = $this->selectedEnrollment;
    $enrollmentId = $enrollment && (int) $enrollment->course_id === (int) $course->id
        ? (int) $enrollment->id
        : null;

    $chartType = in_array($this->chartType, ['progress', 'achievement'], true)
        ? $this->chartType
        : 'progress';

    $query = CompetencyUnit::query()
        ->where('course_id', $course->id)
        ->withCount([
            'tasks as progress_tasks_count' => fn ($taskQuery) => $taskQuery->where('chart_type', 'progress'),
            'tasks as achievement_tasks_count' => fn ($taskQuery) => $taskQuery->where('chart_type', 'achievement'),
        ])
        ->with([
            'tasks' => fn ($query) => $query
                ->where('chart_type', $chartType)
                ->with([
                    'progressRecords' => function ($progressQuery) use ($enrollmentId) {
                        if ($enrollmentId) {
                            $progressQuery->where('enrollment_application_id', $enrollmentId);
                        } else {
                            $progressQuery->whereRaw('1 = 0');
                        }
                    },
                ]),
            'reviews' => function ($query) use ($enrollmentId) {
                if ($enrollmentId) {
                    $query->where('enrollment_application_id', $enrollmentId);
                } else {
                    $query->whereRaw('1 = 0');
                }
            },
        ])
        ->orderBy('sort_order');

    if ($this->unitFilter !== 'all') {
        $query->whereKey((int) $this->unitFilter);
    }

    $units = $query->get();

    if ($this->statusFilter !== 'all') {
        $statusFilter = $this->statusFilter;

        $units = $units->map(function ($unit) use ($statusFilter) {
            $filteredTasks = $unit->tasks
                ->filter(function ($task) use ($statusFilter) {
                    $record = $task->progressRecords->first();
                    $status = $record?->status ?? 'not_assessed';

                    return $status === $statusFilter;
                })
                ->values();

            $unit->setRelation('tasks', $filteredTasks);

            return $unit;
        })
        ->filter(fn ($unit) => $unit->tasks->isNotEmpty())
        ->values();
    }

    return $units;
});
$summary = computed(function () {
    $course = $this->authorizedCourse;
    $enrollment = $this->selectedEnrollment;

    if (! $course || ! $enrollment || (int) $enrollment->course_id !== (int) $course->id) {
        return [
            'total' => 0,
            'competent' => 0,
            'assessed' => 0,
            'remaining' => 0,
            'percent' => 0,
        ];
    }

    $chartType = in_array($this->chartType, ['progress', 'achievement'], true)
        ? $this->chartType
        : 'progress';

    $taskIds = CompetencyTask::query()
        ->where('chart_type', $chartType)
        ->whereHas(
            'competencyUnit',
            fn ($query) => $query->where('course_id', $course->id)
        )
        ->pluck('id');

    $records = CompetencyProgress::query()
        ->where('enrollment_application_id', $enrollment->id)
        ->whereIn('competency_task_id', $taskIds);

    $competent = (clone $records)
        ->where('status', 'competent')
        ->count();

    $assessed = (clone $records)
        ->where('status', '<>', 'not_assessed')
        ->count();

    $total = $taskIds->count();

    return [
        'total' => $total,
        'competent' => $competent,
        'assessed' => $assessed,
        'remaining' => max(0, $total - $competent),
        'percent' => $total > 0
            ? (int) round(($competent / $total) * 100)
            : 0,
    ];
});

$viewUnitChart = function (string $chartType, int $unitId): void {
    abort_unless(in_array($chartType, ['progress', 'achievement'], true), 422);

    $unit = CompetencyUnit::query()
        ->whereKey($unitId)
        ->where('course_id', $this->authorizedCourse?->id)
        ->firstOrFail();

    // Open the selected unit's task-assessment modal using the requested chart.
    $this->chartType = $chartType;
    $this->unitFilter = 'all';
    $this->statusFilter = 'all';
    $this->selectedTaskIds = [];
    $this->selectedUnitId = (int) $unit->id;
    $this->unitModalOpen = true;
};

function updatedCourseId(): void
{
    
    if ($this->courseId && ! $this->authorizedCourse) {
        $this->courseId = null;
    }

    $this->enrollmentId = null;
    $this->unitFilter = 'all';
    $this->statusFilter = 'all';
    $this->selectedTaskIds = [];
    $this->bulkStatus = '';
    $this->unitModalOpen = false;
    $this->selectedUnitId = null;
    $this->verifyModalOpen = false;
    $this->verifyUnitId = null;
}

function updatedEnrollmentId(): void
{
    if ($this->enrollmentId && ! $this->selectedEnrollment) {
        $this->enrollmentId = null;
    }

    $this->selectedTaskIds = [];
    $this->unitModalOpen = false;
    $this->selectedUnitId = null;
}

function updatedChartType(): void
{
    if (! in_array($this->chartType, ['progress', 'achievement'], true)) {
        $this->chartType = 'progress';
    }

    $this->selectedTaskIds = [];
    $this->unitModalOpen = false;
    $this->selectedUnitId = null;
}

function updatedUnitFilter(): void
{
    if (
        $this->unitFilter !== 'all'
        && ! $this->courseUnits->contains(fn ($unit) => (int) $unit->id === (int) $this->unitFilter)
    ) {
        $this->unitFilter = 'all';
    }

    $this->selectedTaskIds = [];
}

function updatedStatusFilter(): void
{
    $allowedFilters = [
        'all',
        'not_assessed',
        'submitted_output_correct',
        'competent',
        'not_yet_competent',
    ];

    if (! in_array($this->statusFilter, $allowedFilters, true)) {
        $this->statusFilter = 'all';
    }

    $this->selectedTaskIds = [];
}
$saveStatus = function (int $enrollmentId, int $taskId, string $status): void {
    $user = auth()->user();

    abort_unless(
        $user
        && $user->account_status === 'active'
        && $user->hasRole('administrator', 'focal_person'),
        403
    );

    $allowedStatuses = [
        'submitted_output_correct',
        'competent',
        'not_yet_competent',
        'not_assessed',
    ];

    abort_unless(in_array($status, $allowedStatuses, true), 422);

    $application = EnrollmentApplication::query()
        ->whereKey($enrollmentId)
        ->where('application_status', 'confirmed')
        ->firstOrFail();

    abort_unless(
        $this->authorizedCourse
        && (int) $this->authorizedCourse->id === (int) $application->course_id
        && $this->selectedEnrollment
        && (int) $this->selectedEnrollment->id === (int) $application->id,
        403,
        'Select this confirmed trainee in the authorized course before updating competency status.'
    );

    if (! $user->hasRole('administrator')) {
        abort_unless(
            $user->institutions()
                ->where('institutions.id', $application->institution_id)
                ->exists(),
            403
        );
    }

    $task = CompetencyTask::query()
        ->with('competencyUnit')
        ->findOrFail($taskId);

    abort_unless(
        $task->competencyUnit
        && in_array($task->chart_type, ['progress', 'achievement'], true)
        && (int) $task->competencyUnit->course_id === (int) $application->course_id,
        403
    );

    DB::transaction(function () use ($application, $task, $status, $user): void {
        $review = CompetencyUnitReview::query()
            ->where('enrollment_application_id', $application->id)
            ->where('competency_unit_id', $task->competency_unit_id)
            ->lockForUpdate()
            ->first();

        abort_if(
            $review?->is_locked,
            403,
            'This competency unit has already been verified and locked.'
        );

        CompetencyProgress::updateOrCreate(
            [
                'enrollment_application_id' => $application->id,
                'competency_task_id' => $task->id,
            ],
            [
                'status' => $status,
                'assessed_by' => $user->id,
                'assessed_at' => now(),
            ]
        );
    });

    ActivityLogger::record(
        'competency_progress_updated',
        "{$user->name} updated the competency status for {$task->name} to {$status}.",
        $application->institution_id,
        [
            'enrollment_application_id' => $application->id,
            'competency_task_id' => $task->id,
            'status' => $status,
        ]
    );

    session()->flash(
        'competency_success',
        'Competency status updated successfully.'
    );
};

$selectVisibleTasks = function (): void {
    if (! $this->selectedEnrollment) {
        $this->selectedTaskIds = [];
        return;
    }

    $this->selectedTaskIds = $this->units
        ->reject(fn ($unit) => (bool) ($unit->reviews->first()?->is_locked ?? false))
        ->flatMap(fn ($unit) => $unit->tasks->pluck('id'))
        ->map(fn ($id) => (string) $id)
        ->values()
        ->all();
};

$clearSelectedTasks = function (): void {
    $this->selectedTaskIds = [];
};

$toggleUnitTasks = function (int $unitId): void {
    if (! $this->selectedEnrollment) {
        return;
    }

    $unit = $this->units->firstWhere('id', $unitId);
    if (! $unit || (bool) ($unit->reviews->first()?->is_locked ?? false)) {
        return;
    }

    $visibleIds = $unit->tasks
        ->where('chart_type', $this->chartType)
        ->pluck('id')->map(fn ($id) => (string) $id)->values();
    $selected = collect($this->selectedTaskIds ?? [])->map(fn ($id) => (string) $id);
    $allSelected = $visibleIds->isNotEmpty() && $visibleIds->every(fn ($id) => $selected->contains($id));

    $this->selectedTaskIds = ($allSelected
        ? $selected->reject(fn ($id) => $visibleIds->contains($id))
        : $selected->merge($visibleIds))
        ->unique()->values()->all();
};

$openUnitModal = function (int $unitId): void {
    abort_unless($this->units->contains(fn ($unit) => (int) $unit->id === $unitId), 404);
    $this->selectedUnitId = $unitId;
    $this->unitModalOpen = true;
};

$requestVerifyUnit = function (int $unitId): void {
    abort_unless($this->selectedEnrollment, 422, 'Select a confirmed scholar first.');
    abort_unless($this->units->contains(fn ($unit) => (int) $unit->id === $unitId), 404);
    $this->unitModalOpen = false;
    $this->verifyUnitId = $unitId;
    $this->verifyModalOpen = true;
};
$bulkUpdateStatus = function (): void {
    $user = auth()->user();

    abort_unless(
        $user
        && $user->account_status === 'active'
        && $user->hasRole('administrator', 'focal_person'),
        403
    );

    abort_unless(in_array($this->chartType, ['progress', 'achievement'], true), 422);

    $status = (string) $this->bulkStatus;

    $allowedStatuses = [
        'submitted_output_correct',
        'competent',
        'not_yet_competent',
        'not_assessed',
    ];

    abort_unless(in_array($status, $allowedStatuses, true), 422);

    $taskIds = collect($this->selectedTaskIds ?? [])
        ->map(fn ($id) => (int) $id)
        ->filter(fn ($id) => $id > 0)
        ->unique()
        ->values();

    abort_unless($taskIds->isNotEmpty() && $taskIds->count() <= 100, 422);

    $authorizedCourse = $this->authorizedCourse;
    abort_unless($authorizedCourse, 403, 'Selected course is not available to your account.');

    $application = EnrollmentApplication::query()
        ->whereKey($this->enrollmentId)
        ->where('course_id', $authorizedCourse->id)
        ->where('application_status', 'confirmed')
        ->firstOrFail();

    abort_unless(
        $this->selectedEnrollment && (int) $this->selectedEnrollment->id === (int) $application->id,
        403,
        'Select a confirmed trainee in the current course.'
    );

    if (! $user->hasRole('administrator')) {
        abort_unless(
            $user->institutions()
                ->where('institutions.id', $application->institution_id)
                ->exists(),
            403
        );
    }

    $tasks = CompetencyTask::query()
        ->with('competencyUnit')
        ->whereIn('id', $taskIds)
        ->where('chart_type', $this->chartType)
        ->when($this->unitModalOpen && $this->selectedUnitId, fn ($query) => $query->where('competency_unit_id', (int) $this->selectedUnitId))
        ->whereHas(
            'competencyUnit',
            fn ($query) => $query->where('course_id', $application->course_id)
        )
        ->get();

    abort_unless($tasks->count() === $taskIds->count(), 403);

    DB::transaction(function () use ($tasks, $application, $status, $user): void {
        foreach ($tasks as $task) {
            $review = CompetencyUnitReview::query()
                ->where('enrollment_application_id', $application->id)
                ->where('competency_unit_id', $task->competency_unit_id)
                ->lockForUpdate()
                ->first();

            abort_if(
                $review?->is_locked,
                403,
                'One or more selected tasks belong to a locked competency unit.'
            );

            CompetencyProgress::updateOrCreate(
                [
                    'enrollment_application_id' => $application->id,
                    'competency_task_id' => $task->id,
                ],
                [
                    'status' => $status,
                    'assessed_by' => $user->id,
                    'assessed_at' => now(),
                ]
            );
        }
    });

    ActivityLogger::record(
        'competency_progress_bulk_updated',
        "{$user->name} bulk-updated {$tasks->count()} competency task(s) to {$status}.",
        $application->institution_id,
        [
            'enrollment_application_id' => $application->id,
            'competency_task_ids' => $tasks->pluck('id')->all(),
            'status' => $status,
        ]
    );

    $count = $tasks->count();
    $this->selectedTaskIds = [];
    $this->bulkStatus = '';

    session()->flash(
        'competency_success',
        "{$count} competency task(s) updated successfully."
    );
};

$verifyUnit = function (int $enrollmentId, int $unitId): void {
    $user = auth()->user();

    abort_unless(
        $user
        && $user->account_status === 'active'
        && $user->hasRole('administrator', 'focal_person'),
        403
    );

    $application = EnrollmentApplication::query()
        ->whereKey($enrollmentId)
        ->where('application_status', 'confirmed')
        ->firstOrFail();

    abort_unless(
        $this->selectedEnrollment && (int) $this->selectedEnrollment->id === (int) $application->id,
        403,
        'Select this confirmed trainee before verifying the unit.'
    );

    if (! $user->hasRole('administrator')) {
        abort_unless(
            $user->institutions()
                ->where('institutions.id', $application->institution_id)
                ->exists(),
            403
        );
    }

    $unit = CompetencyUnit::query()
        ->with('tasks')
        ->findOrFail($unitId);

    abort_unless(
        $this->authorizedCourse
        && (int) $this->authorizedCourse->id === (int) $application->course_id
        && (int) $unit->course_id === (int) $application->course_id,
        403,
        'The selected competency unit does not belong to the authorized course.'
    );

    $taskIds = $unit->tasks->pluck('id');

    if ($taskIds->isEmpty()) {
        $this->unitReviewMessageType = 'error';
        $this->unitReviewMessage = 'This unit has no competency tasks to verify.';
        $this->verifyModalOpen = false;
        $this->unitReviewResultOpen = true;
        return;
    }

    $assessedTaskIds = CompetencyProgress::query()
        ->where('enrollment_application_id', $application->id)
        ->whereIn('competency_task_id', $taskIds)
        ->where('status', '<>', 'not_assessed')
        ->pluck('competency_task_id');

    $pendingCount = $taskIds->diff($assessedTaskIds)->count();

    if ($pendingCount > 0) {
        $this->unitReviewMessageType = 'error';
        $this->unitReviewMessage = "{$pendingCount} task(s) still need assessment before this unit can be verified.";
        $this->verifyModalOpen = false;
        $this->unitReviewResultOpen = true;
        return;
    }

    $verificationResult = DB::transaction(function () use ($application, $unit, $user, $taskIds) {
        $assessedTaskIds = CompetencyProgress::query()
            ->where('enrollment_application_id', $application->id)
            ->whereIn('competency_task_id', $taskIds)
            ->where('status', '<>', 'not_assessed')
            ->pluck('competency_task_id');

        $pendingCount = $taskIds->diff($assessedTaskIds)->count();

        if ($pendingCount > 0) {
            return [
                'status' => 'pending',
                'count' => $pendingCount,
            ];
        }

        $existingReview = CompetencyUnitReview::query()
            ->where('enrollment_application_id', $application->id)
            ->where('competency_unit_id', $unit->id)
            ->lockForUpdate()
            ->first();

        if ($existingReview?->is_locked) {
            return ['status' => 'already_locked'];
        }

        CompetencyUnitReview::updateOrCreate(
            [
                'enrollment_application_id' => $application->id,
                'competency_unit_id' => $unit->id,
            ],
            [
                'status' => 'verified',
                'verified_by' => $user->id,
                'verified_at' => now(),
                'is_locked' => true,
            ]
        );

        ActivityLogger::record(
            'competency_unit_verified',
            "{$user->name} verified and locked competency unit {$unit->name} for {$application->learner_name}.",
            $application->institution_id,
            [
                'enrollment_application_id' => $application->id,
                'competency_unit_id' => $unit->id,
            ]
        );

        return ['status' => 'verified'];
    });

    if ($verificationResult['status'] === 'pending') {
        $this->unitReviewMessageType = 'error';
        $this->unitReviewMessage = "{$verificationResult['count']} task(s) still need assessment before this unit can be verified.";
        $this->verifyModalOpen = false;
        $this->unitReviewResultOpen = true;
        return;
    }

    if ($verificationResult['status'] === 'already_locked') {
        $this->unitReviewMessageType = 'success';
        $this->unitReviewMessage = 'This unit is already verified and locked.';
    } else {
        $this->unitReviewMessageType = 'success';
        $this->unitReviewMessage = 'Competency unit verified and locked successfully.';
    }

    $this->verifyModalOpen = false;
    $this->unitReviewResultOpen = true;
};

$reviewCompetencyReport = function (): void {
    if (! $this->enrollmentId) {
        $this->addError('reportWorkflow', 'Select a confirmed scholar first.');
        return;
    }

    $user = auth()->user();
    abort_unless($user instanceof \App\Models\User, 403);

    $result = app(\App\Support\CompetencyReportWorkflow::class)
        ->review($user, (int) $this->enrollmentId);

    if (! $result['ok']) {
        $this->addError('reportWorkflow', $result['message']);
        return;
    }

    session()->flash('competency_success', $result['message']);
};

$finalizeCompetencyReport = function (): void {
    if (! $this->enrollmentId) {
        $this->addError('reportWorkflow', 'Select a confirmed scholar first.');
        return;
    }

    $user = auth()->user();
    abort_unless($user instanceof \App\Models\User, 403);

    $result = app(\App\Support\CompetencyReportWorkflow::class)
        ->finalize($user, (int) $this->enrollmentId);

    if (! $result['ok']) {
        $this->addError('reportWorkflow', $result['message']);
        return;
    }

    session()->flash('competency_success', $result['message']);
};

$submitCompetencyReport = function (): void {
    if (! $this->enrollmentId) {
        $this->addError('reportWorkflow', 'Select a confirmed scholar first.');
        return;
    }

    $user = auth()->user();
    abort_unless($user instanceof \App\Models\User, 403);

    $result = app(\App\Support\CompetencyReportWorkflow::class)
        ->submit(
            $user,
            (int) $this->enrollmentId,
            $this->submissionReference ?: null,
            $this->submissionRemarks ?: null
        );

    if (! $result['ok']) {
        $this->addError('reportWorkflow', $result['message']);
        return;
    }

    session()->flash('competency_success', $result['message']);
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <header>
        <p class="text-sm font-semibold text-cyan-700">Training Operations</p>
        <h1 class="mt-1 text-3xl font-black text-slate-900">
            Competency Progress & Achievement
        </h1>
        <p class="mt-1 text-sm text-slate-600">
            Record competency task status, monitor completion, and verify completed units.
        </p>
    </header>

    @if (session('competency_success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
            {{ session('competency_success') }}
        </div>
    @endif

    <section class="sticky top-2 z-30 space-y-4 rounded-xl border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-base font-bold text-slate-900">Selected Course & Scholar</h2><span class="text-xs font-medium text-slate-500">Selection stays visible while you work</span></div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label for="courseId" class="mb-2 block text-sm font-medium text-slate-700">
                    Course
                </label>
                <select
                    id="courseId"
                    wire:model.live="courseId"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100"
                >
                    <option value="">Select a course</option>
                    @foreach ($this->courses as $course)
                        <option value="{{ $course->id }}">
                            {{ $course->name }} — {{ $course->institution?->name ?? 'Institution' }}
                        </option>
                    @endforeach
                </select>

                @if ($this->courses->isEmpty())
                    <p class="mt-2 text-sm text-amber-700">
                        No active courses are available for your assigned institutions.
                    </p>
                @endif
            </div>

            <div>
                <label for="enrollmentId" class="mb-2 block text-sm font-medium text-slate-700">
                    Confirmed Scholar
                </label>
                <select
                    id="enrollmentId"
                    wire:model.live="enrollmentId"
                    {{ $this->courseId ? '' : 'disabled' }}
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100 disabled:bg-slate-100"
                >
                    <option value="">Select a scholar</option>
                    @foreach ($this->applications as $application)
                        <option value="{{ $application->id }}">
                            {{ $application->learner_name }} — {{ $application->scholar_id ?: $application->reference_no }}
                        </option>
                    @endforeach
                </select>

                @if ($this->courseId && $this->applications->isEmpty())
                    <p class="mt-2 text-sm text-amber-700">
                        No confirmed scholars are assigned to this course.
                    </p>
                @endif
            </div>
        </div>

        @if ($this->selectedEnrollment)
            <div class="grid gap-3 rounded-lg bg-slate-50 p-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Scholar</p>
                    <p class="mt-1 font-semibold text-slate-900">
                        {{ $this->selectedEnrollment->learner_name }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Institution</p>
                    <p class="mt-1 font-semibold text-slate-900">
                        {{ $this->selectedEnrollment->institution?->name ?? 'N/A' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Training Batch</p>
                    <p class="mt-1 font-semibold text-slate-900">
                        {{ $this->selectedEnrollment->trainingBatch?->name ?? 'Not assigned' }}
                    </p>
                </div>
            </div>
        @endif
    </section>

    <nav class="sticky top-[7.5rem] z-20 rounded-xl border border-slate-200 bg-white/95 p-2 shadow-sm backdrop-blur print:hidden" aria-label="Competency sections">
        <div class="grid grid-cols-3 gap-2">
            <button type="button" wire:click="$set('activeTab', 'dashboard')" class="rounded-lg px-3 py-3 text-sm font-semibold transition {{ ($activeTab ?? 'dashboard') === 'dashboard' ? 'bg-cyan-700 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' }}">Dashboard</button>
            <button type="button" wire:click="$set('activeTab', 'units')" class="rounded-lg px-3 py-3 text-sm font-semibold transition {{ ($activeTab ?? 'dashboard') === 'units' ? 'bg-cyan-700 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' }}">Competency Units</button>
            <button type="button" wire:click="$set('activeTab', 'reports')" class="rounded-lg px-3 py-3 text-sm font-semibold transition {{ ($activeTab ?? 'dashboard') === 'reports' ? 'bg-cyan-700 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' }}">Reports</button>
        </div>
    </nav>

    @if (($activeTab ?? 'dashboard') === 'reports' && !$this->selectedEnrollment)
        <section class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">Select a scholar first</h2>
            <p class="mt-1 text-sm text-slate-600">Choose a course and confirmed scholar above to access the report workflow.</p>
        </section>
    @endif
    @if (($activeTab ?? 'dashboard') === 'reports' && $this->selectedEnrollment)
    <section id="competency-report-workflow" class="print:hidden scroll-mt-28 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Report Submission Workflow</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Review, finalize, and record submission of this scholar's competency report.
                </p>

                <p class="mt-3 text-sm text-slate-700">
                    Current status:
                    <span class="font-bold uppercase tracking-wide">
                        {{ str_replace('_', ' ', $this->reportSubmission?->status ?? 'draft') }}
                    </span>
                </p>

                @if ($this->reportSubmission?->reviewed_at)
                    <p class="mt-2 text-xs text-slate-500">
                        Reviewed by: {{ $this->reportSubmission->reviewer?->name ?? 'Former user' }}
                        — {{ $this->reportSubmission->reviewed_at->format('M d, Y h:i A') }}
                    </p>
                @endif

                @if ($this->reportSubmission?->finalized_at)
                    <p class="mt-1 text-xs text-slate-500">
                        Finalized by: {{ $this->reportSubmission->finalizer?->name ?? 'Former user' }}
                        — {{ $this->reportSubmission->finalized_at->format('M d, Y h:i A') }}
                    </p>
                @endif

                @if ($this->reportSubmission?->submitted_at)
                    <p class="mt-1 text-xs text-slate-500">
                        Submission recorded by: {{ $this->reportSubmission->submitter?->name ?? 'Former user' }}
                        — {{ $this->reportSubmission->submitted_at->format('M d, Y h:i A') }}
                    </p>
                @endif

                @if ($errors->has('reportWorkflow'))
                    <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        {{ $errors->first('reportWorkflow') }}
                    </p>
                @endif

                <p class="mt-3 max-w-2xl text-xs leading-5 text-slate-500">
                    Reports can proceed only when all competency tasks are assessed and all units are verified and locked.
                    Submitting here records the workflow in TESDA Connect; it does not transmit the report to an external TESDA system.
                </p>

                @if (($this->reportSubmission?->status ?? 'draft') === 'finalized')
                    <div class="mt-4 grid gap-3">
                        <div>
                            <label for="submissionReference" class="mb-1 block text-sm font-medium text-slate-700">
                                Submission Reference (optional)
                            </label>
                            <input
                                id="submissionReference"
                                type="text"
                                maxlength="100"
                                wire:model="submissionReference"
                                placeholder="Enter the reference provided by TESDA Provincial"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label for="submissionRemarks" class="mb-1 block text-sm font-medium text-slate-700">
                                Submission Remarks (optional)
                            </label>
                            <textarea
                                id="submissionRemarks"
                                rows="3"
                                maxlength="2000"
                                wire:model="submissionRemarks"
                                placeholder="Add submission notes, if applicable"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            ></textarea>
                        </div>
                    </div>
                @endif

                @if (($this->reportSubmission?->status ?? 'draft') === 'submitted')
                    <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                        @if ($this->reportSubmission->submission_reference)
                            <p class="text-sm text-emerald-900">
                                <strong>Submission Reference:</strong>
                                {{ $this->reportSubmission->submission_reference }}
                            </p>
                        @endif
                        @if ($this->reportSubmission->remarks)
                            <p class="mt-2 whitespace-pre-line text-sm text-emerald-900">
                                <strong>Remarks:</strong>
                                {{ $this->reportSubmission->remarks }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="reviewCompetencyReport"
                    wire:loading.attr="disabled"
                    {{ ($this->reportSubmission?->status ?? 'draft') !== 'draft' ? 'disabled' : '' }}
                    class="rounded-lg bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Review Report
                </button>

                <button
                    type="button"
                    wire:click="finalizeCompetencyReport"
                    wire:confirm="Finalize this reviewed competency report? Please confirm that the report has been checked."
                    wire:loading.attr="disabled"
                    {{ ($this->reportSubmission?->status ?? 'draft') !== 'reviewed' ? 'disabled' : '' }}
                    class="rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Finalize Report
                </button>

                <button
                    type="button"
                    wire:click="submitCompetencyReport"
                    wire:confirm="Record this finalized report as submitted to TESDA Provincial? This does not send it electronically."
                    wire:loading.attr="disabled"
                    {{ ($this->reportSubmission?->status ?? 'draft') !== 'finalized' ? 'disabled' : '' }}
                    class="rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Submit to TESDA Provincial
                </button>
            </div>
        </div>
    </section>
    @endif
    @if (($activeTab ?? 'dashboard') === 'dashboard')
    <div class="space-y-5">
    <section class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div>
            <h2 class="font-bold text-slate-900">Progress Overview</h2>
            <p class="mt-1 text-sm text-slate-500">Progress Chart and Achievement Chart are separate task sets. Select the chart to view and assess its own tasks.</p>
        </div>
        <div class="flex gap-2">
            <button type="button" wire:click="$set('chartType', 'progress')" class="rounded-lg px-3 py-2 text-sm font-semibold {{ $this->chartType === 'progress' ? 'bg-cyan-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Progress Chart</button>
            <button type="button" wire:click="$set('chartType', 'achievement')" class="rounded-lg px-3 py-2 text-sm font-semibold {{ $this->chartType === 'achievement' ? 'bg-cyan-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Achievement Chart</button>
        </div>
    </section>
    @if (!$this->selectedEnrollment)
        <section class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">Choose a course and scholar</h2>
            <p class="mt-1 text-sm text-slate-600">Select a confirmed scholar above to view competency progress and achievement.</p>
        </section>
    @endif
    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <label for="unitFilter" class="mb-2 block text-sm font-semibold text-slate-700">
            Filter by Competency Unit
        </label>
        <select
            id="unitFilter"
            wire:model.live="unitFilter"
            {{ $this->courseId ? '' : 'disabled' }}
            class="w-full max-w-xl rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 disabled:bg-slate-100"
        >
            <option value="all">All competency units</option>
            @foreach ($this->courseUnits as $courseUnit)
                <option value="{{ $courseUnit->id }}">
                    Unit {{ $courseUnit->unit_number }} — {{ $courseUnit->name }}
                </option>
            @endforeach
        </select>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <label for="statusFilter" class="mb-2 block text-sm font-semibold text-slate-700">
            Filter by Status
        </label>

        <select
            id="statusFilter"
            wire:model.live="statusFilter"
            class="w-full max-w-xl rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
        >
            <option value="all">All statuses</option>
            <option value="not_assessed">Not Assessed</option>
            <option value="submitted_output_correct">Submitted Output Correct</option>
            <option value="competent">Competent (C)</option>
            <option value="not_yet_competent">Not Yet Competent (NYC)</option>
        </select>

        <p class="mt-2 text-xs text-slate-500">
            The filter applies to the currently selected chart.
        </p>
    </section>
    @if ($this->selectedEnrollment)
        @php
            $summary = $this->summary;
        @endphp

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-600">{{ $this->chartType === 'progress' ? 'Progress Chart Tasks' : 'Achievement Chart Tasks' }}</p>
                <p class="mt-3 text-3xl font-black text-slate-900">{{ $summary['total'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-600">Competent Tasks</p>
                <p class="mt-3 text-3xl font-black text-emerald-700">{{ $summary['competent'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-600">Tasks Assessed</p>
                <p class="mt-3 text-3xl font-black text-cyan-700">{{ $summary['assessed'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-600">Remaining Tasks</p>
                <p class="mt-3 text-3xl font-black text-amber-700">{{ $summary['remaining'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-600">Competency Completion</p>
                <p class="mt-3 text-3xl font-black text-indigo-700">{{ $summary['percent'] }}%</p>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-indigo-600" style="width: {{ $summary['percent'] }}%"></div>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    Based on Competent tasks in the {{ $this->chartType === 'progress' ? 'Progress Chart' : 'Achievement Chart' }}.
                </p>
            </div>
        </section>
    @endif

    </div>
    @endif

    @if (($activeTab ?? 'dashboard') === 'units')
    <div class="space-y-5">
    <section class="print:hidden flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div>
            <h2 class="font-bold text-slate-900">Bulk Update Tasks</h2>
            <p class="mt-1 text-sm text-slate-500">
                {{ count($this->selectedTaskIds) }} task(s) selected. Update them together without leaving the competency list.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="selectVisibleTasks" {{ !$this->selectedEnrollment ? 'disabled' : '' }}
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                Select Visible Tasks
            </button>
            <button type="button" wire:click="clearSelectedTasks"
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Clear
            </button>
            <flux:modal.trigger name="competency-bulk-update">
                <flux:button variant="primary" :disabled="!$this->selectedEnrollment || count($this->selectedTaskIds) === 0">
                    Bulk Update
                </flux:button>
            </flux:modal.trigger>
        </div>
    </section>

    <flux:modal name="competency-bulk-update" class="md:w-[32rem]">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Bulk Update Tasks</flux:heading>
                <flux:subheading>
                    Apply one competency status to {{ count($this->selectedTaskIds) }} selected task(s).
                </flux:subheading>
            </div>

            <form wire:submit="bulkUpdateStatus" class="space-y-4">
                <flux:field>
                    <flux:label>Status to apply</flux:label>
                    <flux:select wire:model.live="bulkStatus" placeholder="Choose a status">
                        <flux:select.option value="not_assessed">Not Assessed</flux:select.option>
                        <flux:select.option value="submitted_output_correct">Submitted Output Correct</flux:select.option>
                        <flux:select.option value="competent">Competent (C)</flux:select.option>
                        <flux:select.option value="not_yet_competent">Not Yet Competent (NYC)</flux:select.option>
                    </flux:select>
                    <flux:error name="bulkStatus" />
                </flux:field>

                <div class="flex items-center justify-between gap-3">
                    <flux:button type="button" variant="ghost" x-on:click="$flux.modal('competency-bulk-update').close()">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary" :disabled="!$this->selectedEnrollment || count($this->selectedTaskIds) === 0 || !$this->bulkStatus" wire:loading.attr="disabled">
                        Apply to Selected
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal name="competency-verify-unit" wire:model="verifyModalOpen" class="md:w-[34rem]">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Verify and lock competency unit?</flux:heading>
                <flux:subheading>All tasks in both Progress Chart and Achievement Chart must be assessed. After locking, statuses for this scholar and unit can no longer be changed.</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('verifyModalOpen', false)">Cancel</flux:button>
                <flux:button type="button" variant="primary" wire:click="verifyUnit({{ $this->selectedEnrollment?->id ?? 0 }}, {{ (int) $verifyUnitId }})" wire:loading.attr="disabled" wire:target="verifyUnit">Verify &amp; Lock</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="competency-unit-review-result" wire:model="unitReviewResultOpen" class="md:w-[30rem]">
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full {{ $unitReviewMessageType === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                    {{ $unitReviewMessageType === 'success' ? '✓' : '!' }}
                </div>
                <div class="space-y-1">
                    <flux:heading size="lg">{{ $unitReviewMessageType === 'success' ? 'Unit verification' : 'Unable to verify unit' }}</flux:heading>
                    <flux:text>{{ $unitReviewMessage }}</flux:text>
                </div>
            </div>
            <div class="flex justify-end">
                <flux:button type="button" variant="primary" x-on:click="$flux.modal('competency-unit-review-result').close()">Close</flux:button>
            </div>
        </div>
    </flux:modal>


    @if (!$this->courseId)
        <section class="rounded-xl border border-slate-200 bg-white p-10 text-center shadow-sm">
            <div class="text-4xl text-slate-400">☷</div>
            <h2 class="mt-3 text-lg font-bold text-slate-900">Choose a course and scholar</h2>
            <p class="mt-1 text-sm text-slate-600">
                Competency tasks and status controls will appear after you select a confirmed scholar.
            </p>
        </section>
    @else
        @if ($this->units->isEmpty())
            <section class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                <h2 class="text-lg font-bold text-slate-900">No competency units found</h2>
                <p class="mt-1 text-sm text-slate-600">
                    This course does not have any configured competency units yet.
                </p>
            </section>
        @else
            @foreach ($this->units as $unit)
                @php
                    $unitReview = $unit->reviews->first();
                    $isLocked = (bool) ($unitReview?->is_locked ?? false);
                    $unitTaskIds = $unit->tasks->pluck('id')->map(fn ($id) => (string) $id);
                    $selectedIds = collect($this->selectedTaskIds)->map(fn ($id) => (string) $id);
                    $allUnitTasksSelected = $unitTaskIds->isNotEmpty() && $unitTaskIds->every(fn ($id) => $selectedIds->contains($id));
                @endphp
                <section wire:key="competency-unit-card-{{ $unit->id }}" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-cyan-700">Competency Unit {{ $unit->unit_number }}</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-900">{{ $unit->name }}</h2>
                            <p class="mt-1 text-xs text-slate-500">{{ $unit->progress_tasks_count }} Progress Chart task(s) · {{ $unit->achievement_tasks_count }} Achievement Chart task(s)</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 print:hidden">
                            @if ((int) $unit->progress_tasks_count > 0 || (int) $unit->achievement_tasks_count > 0)
                                <button type="button" wire:click="viewUnitChart('{{ (int) $unit->progress_tasks_count > 0 ? 'progress' : 'achievement' }}', {{ $unit->id }})" {{ !$this->selectedEnrollment ? 'disabled' : '' }} class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-50">Manage Tasks</button>
                            @endif
                            @if ($isLocked)
                                <span class="rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-800">Verified &amp; Locked</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-800">Pending Verification</span>
                            @endif
                        </div>
                    </div>
                </section>
            @endforeach

            @php $activeUnit = $this->units->firstWhere('id', (int) $selectedUnitId); @endphp
            <flux:modal name="competency-unit-tasks" wire:model="unitModalOpen" class="w-full max-w-6xl">
                @if ($activeUnit)
                    @php
                        $activeUnitLocked = (bool) ($activeUnit->reviews->first()?->is_locked ?? false);
                        $activeChartTasks = $activeUnit->tasks->where('chart_type', $this->chartType)->values();
                        $activeUnitTaskIds = $activeChartTasks->pluck('id')->map(fn ($id) => (string) $id);
                        $activeSelectedIds = collect($this->selectedTaskIds)->map(fn ($id) => (string) $id);
                        $activeAllSelected = $activeUnitTaskIds->isNotEmpty() && $activeUnitTaskIds->every(fn ($id) => $activeSelectedIds->contains($id));
                    @endphp
                    <div class="space-y-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <flux:heading size="lg">Unit {{ $activeUnit->unit_number }} — {{ $activeUnit->name }}</flux:heading>
                                <flux:subheading>{{ $this->chartType === 'progress' ? 'Progress Chart' : 'Achievement Chart' }} tasks for the selected scholar.</flux:subheading>
                            </div>
                            @if ($activeUnitLocked)<flux:badge color="green">Verified &amp; Locked</flux:badge>@endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-4">
                            @if ((int) $activeUnit->progress_tasks_count > 0)
                                <button type="button" wire:click="viewUnitChart('progress', {{ $activeUnit->id }})" class="rounded-lg px-4 py-2 text-sm font-semibold {{ $this->chartType === 'progress' ? 'bg-cyan-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Progress Chart ({{ $activeUnit->progress_tasks_count }})</button>
                            @endif
                            @if ((int) $activeUnit->achievement_tasks_count > 0)
                                <button type="button" wire:click="viewUnitChart('achievement', {{ $activeUnit->id }})" class="rounded-lg px-4 py-2 text-sm font-semibold {{ $this->chartType === 'achievement' ? 'bg-violet-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Achievement Chart ({{ $activeUnit->achievement_tasks_count }})</button>
                            @endif
                        </div>
                        @if ($this->selectedEnrollment && !$activeUnitLocked && $activeChartTasks->isNotEmpty())
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                <input type="checkbox" wire:click="toggleUnitTasks({{ $activeUnit->id }})" {{ $activeAllSelected ? 'checked' : '' }} class="size-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600" />
                                Check all {{ $activeChartTasks->count() }} {{ $this->chartType === 'progress' ? 'Progress Chart' : 'Achievement Chart' }} tasks
                            </label>
                        @endif
                        @if ($activeChartTasks->isEmpty())
                            <div class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">No tasks are configured for this chart in this unit.</div>
                        @else
                            <div class="max-h-[60vh] overflow-auto rounded-lg border border-slate-200">
                                <table class="w-full min-w-[760px] text-left text-sm">
                                    <thead class="sticky top-0 border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">No.</th><th class="px-4 py-3">Select</th><th class="px-4 py-3">Competency Task</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Last Updated</th></tr></thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($activeChartTasks as $task)
                                            @php $record = $task->progressRecords->first(); $currentStatus = $record?->status ?? 'not_assessed'; @endphp
                                            <tr wire:key="competency-modal-task-{{ $task->id }}">
                                                <td class="px-4 py-3 text-slate-500">{{ $task->task_number }}</td>
                                                <td class="px-4 py-3"><input type="checkbox" wire:model.live="selectedTaskIds" value="{{ $task->id }}" {{ ($activeUnitLocked || !$this->selectedEnrollment) ? 'disabled' : '' }} class="size-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600" /></td>
                                                <td class="px-4 py-3 font-medium text-slate-900">{{ $task->name }}</td>
                                                <td class="px-4 py-3">
                                                    <select aria-label="Status for {{ $task->name }}" data-offline-competency-status data-enrollment-id="{{ $this->selectedEnrollment?->id ?? '' }}" data-task-id="{{ $task->id }}" data-base-status="{{ $currentStatus }}" data-base-updated-at="{{ $record?->updated_at?->format('Y-m-d H:i:s') ?? '' }}" wire:change="saveStatus({{ $this->selectedEnrollment?->id ?? 0 }}, {{ $task->id }}, $event.target.value)" {{ ($activeUnitLocked || !$this->selectedEnrollment) ? 'disabled' : '' }} class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 disabled:bg-slate-100">
                                                        <option value="not_assessed" {{ $currentStatus === 'not_assessed' ? 'selected' : '' }}>Not Assessed</option>
                                                        <option value="submitted_output_correct" {{ $currentStatus === 'submitted_output_correct' ? 'selected' : '' }}>Submitted Output Correct</option>
                                                        <option value="competent" {{ $currentStatus === 'competent' ? 'selected' : '' }}>Competent (C)</option>
                                                        <option value="not_yet_competent" {{ $currentStatus === 'not_yet_competent' ? 'selected' : '' }}>Not Yet Competent (NYC)</option>
                                                    </select>
                                                </td>
                                                <td class="px-4 py-3 text-xs text-slate-500">{{ $record?->assessed_at?->format('M d, Y h:i A') ?? 'Not updated' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        @if (!$activeUnitLocked && $this->selectedEnrollment && $activeChartTasks->isNotEmpty())
                            <div class="flex flex-wrap items-end justify-between gap-3 rounded-lg border border-cyan-100 bg-cyan-50/60 p-3">
                                <div class="min-w-0 flex-1">
                                    <label for="unitBulkStatus" class="mb-1 block text-sm font-semibold text-slate-700">Set status for selected tasks</label>
                                    <select id="unitBulkStatus" wire:model.live="bulkStatus" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                                        <option value="">Choose status to apply</option>
                                        <option value="not_assessed">Not Assessed</option>
                                        <option value="submitted_output_correct">Submitted Output Correct</option>
                                        <option value="competent">Competent (C)</option>
                                        <option value="not_yet_competent">Not Yet Competent (NYC)</option>
                                    </select>
                                </div>
                                <button type="button" wire:click="bulkUpdateStatus" wire:loading.attr="disabled" {{ count($this->selectedTaskIds) === 0 || !$this->bulkStatus ? 'disabled' : '' }} class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-50">
                                    Apply to {{ count($this->selectedTaskIds) }} selected
                                </button>
                            </div>
                        @endif
                        <div class="flex flex-wrap justify-between gap-2 border-t border-slate-200 pt-4">
                            <flux:button type="button" variant="ghost" wire:click="$set('unitModalOpen', false)">Close</flux:button>
                            @if (!$activeUnitLocked && $this->selectedEnrollment)
                                <flux:button type="button" variant="primary" wire:click="requestVerifyUnit({{ $activeUnit->id }})">Verify &amp; Lock Unit</flux:button>
                            @endif
                        </div>
                    </div>
                @endif
            </flux:modal>
        @endif
    @endif
    </div>
    @endif
</div>