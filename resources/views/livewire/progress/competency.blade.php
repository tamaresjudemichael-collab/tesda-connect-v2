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
    'submissionReference' => '',
    'submissionRemarks' => '',
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

    $taskIds = CompetencyTask::query()
        ->where('chart_type', 'progress')
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

function updatedCourseId(): void
{
    // A manipulated Livewire state must not expose a course outside the user's scope.
    if ($this->courseId && ! $this->authorizedCourse) {
        $this->courseId = null;
    }

    $this->enrollmentId = null;
    $this->unitFilter = 'all';
    $this->statusFilter = 'all';
    $this->selectedTaskIds = [];
    $this->bulkStatus = '';
}

function updatedEnrollmentId(): void
{
    if ($this->enrollmentId && ! $this->selectedEnrollment) {
        $this->enrollmentId = null;
    }

    $this->selectedTaskIds = [];
}

function updatedChartType(): void
{
    if (! in_array($this->chartType, ['progress', 'achievement'], true)) {
        $this->chartType = 'progress';
    }

    $this->selectedTaskIds = [];
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
        $this->addError(
            'unitReview',
            'This unit has no competency tasks to verify.'
        );
        return;
    }

    $assessedTaskIds = CompetencyProgress::query()
        ->where('enrollment_application_id', $application->id)
        ->whereIn('competency_task_id', $taskIds)
        ->where('status', '<>', 'not_assessed')
        ->pluck('competency_task_id');

    $pendingCount = $taskIds->diff($assessedTaskIds)->count();

    if ($pendingCount > 0) {
        $this->addError(
            'unitReview',
            "{$pendingCount} task(s) still need assessment before this unit can be verified."
        );
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
        $this->addError(
            'unitReview',
            "{$verificationResult['count']} task(s) still need assessment before this unit can be verified."
        );
        return;
    }

    if ($verificationResult['status'] === 'already_locked') {
        session()->flash(
            'competency_success',
            'This unit is already verified and locked.'
        );
        return;
    }

    session()->flash(
        'competency_success',
        'Competency unit verified and locked successfully.'
    );
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

    @if ($errors->has('unitReview'))
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ $errors->first('unitReview') }}
        </div>
    @endif

    <section class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 class="text-lg font-bold text-slate-900">Select Course and Trainee</h2>

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

    @if ($this->selectedEnrollment)
    <section class="print:hidden rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
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
    @if ($this->selectedEnrollment && $this->chartType === 'progress')
        @php
            $summary = $this->summary;
        @endphp

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-600">Progress Chart Tasks</p>
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
                    Based on Competent tasks in the Progress Chart.
                </p>
            </div>
        </section>
    @endif

        <form wire:submit="bulkUpdateStatus" class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-[1fr_1fr_auto] md:items-end">
        <div>
            <h2 class="text-base font-bold text-slate-900">Bulk Update Tasks</h2>
            <p class="mt-1 text-sm text-slate-500">
                Select tasks below, choose a status, and apply it to the selected tasks.
            </p>
            <p class="mt-1 text-xs font-semibold text-cyan-700">
                Selected: {{ count($this->selectedTaskIds) }} task(s)
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="selectVisibleTasks"
                    {{ !$this->selectedEnrollment ? 'disabled' : '' }}
                    class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Select Visible Tasks
                </button>
                <button
                    type="button"
                    wire:click="clearSelectedTasks"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                >
                    Clear Selection
                </button>
            </div>
        </div>
        <div>
            <label for="bulkStatus" class="mb-2 block text-sm font-medium text-slate-700">
                Status to apply
            </label>
            <select
                id="bulkStatus"
                wire:model.live="bulkStatus"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
            >
                <option value="">Choose a status</option>
                <option value="not_assessed">Not Assessed</option>
                <option value="submitted_output_correct">Submitted Output Correct</option>
                <option value="competent">Competent (C)</option>
                <option value="not_yet_competent">Not Yet Competent (NYC)</option>
            </select>
        </div>
        <button
            type="submit"
            wire:loading.attr="disabled"
            {{ (!$this->selectedEnrollment || count($this->selectedTaskIds) === 0 || !$this->bulkStatus) ? 'disabled' : '' }}
            class="rounded-lg bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-50"
        >
            Apply to Selected
        </button>
    </form>
<div class="flex flex-wrap gap-2">
        <button
            type="button"
            wire:click="$set('chartType', 'progress')"
            class="rounded-lg px-4 py-2.5 text-sm font-semibold {{ $this->chartType === 'progress' ? 'bg-cyan-700 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50' }}"
        >
            Progress Chart
        </button>
        <button
            type="button"
            wire:click="$set('chartType', 'achievement')"
            class="rounded-lg px-4 py-2.5 text-sm font-semibold {{ $this->chartType === 'achievement' ? 'bg-cyan-700 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50' }}"
        >
            Achievement Chart
        </button>
        <button
            type="button"
            onclick="window.print()"
            {{ !$this->selectedEnrollment ? 'disabled' : '' }}
            class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 print:hidden"
        >
            Print Report
        </button>
    </div>

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
                @endphp

                <section wire:key="competency-unit-{{ $unit->id }}" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-5 py-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-cyan-700">
                                Competency Unit {{ $unit->unit_number }}
                            </p>
                            <h2 class="mt-1 text-lg font-bold text-slate-900">{{ $unit->name }}</h2>
                        </div>

                        @if ($isLocked)
                            <span class="rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-800">
                                Verified & Locked
                            </span>
                        @else
                            <span class="rounded-full bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-800">
                                Pending Verification
                            </span>
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[780px] text-left text-sm">
                            <thead class="border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="w-16 px-4 py-3">No.</th>
                                    <th class="w-12 px-4 py-3">Select</th>
                                    <th class="px-4 py-3">Competency Task</th>
                                    <th class="w-72 px-4 py-3">Status</th>
                                    <th class="w-44 px-4 py-3">Last Updated</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @if ($unit->tasks->isEmpty())
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-slate-500">
                                            No tasks have been configured for this chart and unit.
                                        </td>
                                    </tr>
                                @else
                                    @foreach ($unit->tasks as $task)
                                        @php
                                            $record = $task->progressRecords->first();
                                            $currentStatus = $record?->status ?? 'not_assessed';
                                        @endphp

                                        <tr wire:key="competency-task-{{ $task->id }}">
                                            <td class="px-4 py-3 text-slate-500">{{ $task->task_number }}</td>
                                            <td class="px-4 py-3">
                                                <input type="checkbox" wire:model.live="selectedTaskIds" value="{{ $task->id }}" {{ ($isLocked || !$this->selectedEnrollment) ? 'disabled' : '' }} aria-label="Select {{ $task->name }}" class="size-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600" />
                                            </td>
                                            <td class="px-4 py-3 font-medium text-slate-900">{{ $task->name }}</td>
                                            <td class="px-4 py-3">
                                                <select
                                                    aria-label="Status for {{ $task->name }}"
                                                    data-offline-competency-status
                                                    data-enrollment-id="{{ $this->selectedEnrollment?->id ?? '' }}"
                                                    data-task-id="{{ $task->id }}"
                                                    data-base-status="{{ $currentStatus }}"
                                                    data-base-updated-at="{{ $record?->updated_at?->format('Y-m-d H:i:s') ?? '' }}"
                                                    wire:change="saveStatus({{ $this->selectedEnrollment?->id ?? 0 }}, {{ $task->id }}, $event.target.value)"
                                                    {{ ($isLocked || !$this->selectedEnrollment) ? 'disabled' : '' }}
                                                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100 disabled:bg-slate-100 disabled:text-slate-500"
                                                >
                                                    <option value="not_assessed" {{ $currentStatus === 'not_assessed' ? 'selected' : '' }}>Not Assessed</option>
                                                    <option value="submitted_output_correct" {{ $currentStatus === 'submitted_output_correct' ? 'selected' : '' }}>✔ Submitted Output Correct</option>
                                                    <option value="competent" {{ $currentStatus === 'competent' ? 'selected' : '' }}>C — Competent</option>
                                                    <option value="not_yet_competent" {{ $currentStatus === 'not_yet_competent' ? 'selected' : '' }}>NYC — Not Yet Competent</option>
                                                </select>
                                            </td>
                                            <td class="px-4 py-3 text-xs text-slate-500">
                                                {{ $record?->assessed_at?->format('M d, Y h:i A') ?? 'Not updated' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
                        <p class="text-sm text-slate-500">
                            {{ $unit->tasks->count() }} task(s) in this chart
                        </p>

                        @if ($isLocked)
                            <span class="text-sm font-medium text-emerald-700">
                                This unit is locked and can no longer be edited.
                            </span>
                        @elseif (!$this->selectedEnrollment)
                            <span class="text-sm font-medium text-amber-700">
                                Select a confirmed scholar before verifying this unit.
                            </span>
                        @else
                            <button
                                type="button"
                                wire:click="verifyUnit({{ $this->selectedEnrollment?->id ?? 0 }}, {{ $unit->id }})"
                                wire:confirm="Verify and lock this unit? All tasks in both charts must be assessed. After verification, the statuses cannot be changed."
                                wire:loading.attr="disabled"
                                class="rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
                            >
                                Verify & Lock Unit
                            </button>
                        @endif
                    </div>
                </section>
            @endforeach
        @endif
    @endif
</div>