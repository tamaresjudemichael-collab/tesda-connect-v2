<?php

use App\Models\ActivityLog;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Models\TrainingCertificate;
use App\Models\User;
use App\Support\InstitutionScope;
use Flux\Flux;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Dashboard');

$dashboard = computed(function (): array {
    $user = auth()->user();
    $institutionIds = InstitutionScope::idsFor($user);
    $applications = EnrollmentApplication::query()->whereIn('institution_id', $institutionIds);
    $total = (clone $applications)->count();
    $complete = (clone $applications)->where('record_completeness', 100)->count();

    $administratorOverview = null;

    if ($user->hasRole('administrator')) {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $deadlineStart = today();
        $deadlineEnd = today()->addDays(30);
        $pendingStatuses = ['submitted', 'under_review', 'returned_for_revision'];
        $institutionRecords = Institution::query()
            ->whereIn('code', ['BST', 'MIF'])
            ->get()
            ->keyBy('code');

        $comparison = collect(['BST', 'MIF'])->mapWithKeys(function (string $code) use (
            $institutionRecords,
            $monthStart,
            $monthEnd,
            $pendingStatuses,
        ): array {
            $institution = $institutionRecords->get($code);

            if (! $institution) {
                return [$code => [
                    'name' => $code,
                    'enrollment_records' => 0,
                    'monthly_intake' => 0,
                    'pending' => 0,
                    'confirmed' => 0,
                    'completion_rate' => 0,
                ]];
            }

            $applications = EnrollmentApplication::query()->where('institution_id', $institution->id);
            $confirmed = (clone $applications)->where('application_status', 'confirmed')->count();
            $completed = TrainingCertificate::query()
                ->where('status', 'completed')
                ->whereHas('enrollmentApplication', fn ($query) => $query->where('institution_id', $institution->id))
                ->count();

            return [$code => [
                'name' => $institution->name,
                'enrollment_records' => (clone $applications)->count(),
                'monthly_intake' => (clone $applications)->whereBetween('submitted_at', [$monthStart, $monthEnd])->count(),
                'pending' => (clone $applications)->whereIn('application_status', $pendingStatuses)->count(),
                'confirmed' => $confirmed,
                'completion_rate' => $confirmed === 0 ? 0 : (int) round(min(100, ($completed / $confirmed) * 100)),
            ]];
        });

        $allApplications = EnrollmentApplication::query();
        $administratorOverview = [
            'active_users' => User::query()->where('account_status', 'active')->count(),
            'confirmed_enrollees' => (clone $allApplications)->where('application_status', 'confirmed')->count(),
            'pending_tasks' => (clone $allApplications)->whereIn('application_status', $pendingStatuses)->count(),
            'this_month_intake' => (clone $allApplications)->whereBetween('submitted_at', [$monthStart, $monthEnd])->count(),
            'pending_verifications' => (clone $allApplications)->whereIn('application_status', ['submitted', 'under_review'])->count(),
            'upcoming_schedule_deadlines' => TrainingBatch::query()
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->whereBetween('ends_at', [$deadlineStart->toDateString(), $deadlineEnd->toDateString()])
                ->count(),
            'comparison' => $comparison,
        ];
    }

    $focalOverview = null;

    if ($user->hasRole('focal_person')) {
        $confirmedApplications = EnrollmentApplication::query()
            ->whereIn('institution_id', $institutionIds)
            ->where('application_status', 'confirmed')
            ->whereNull('archived_at');

        $focalOverview = [
            'assigned_institutions' => $user->institutions()
                ->where('institutions.is_active', true)
                ->withCount('courses')
                ->orderBy('institutions.name')
                ->get(['institutions.id', 'institutions.code', 'institutions.name']),
            'confirmed_trainees' => (clone $confirmedApplications)->count(),
            'pending_encodings' => (clone $confirmedApplications)->whereDoesntHave('competencyProgress')->count(),
            'pending_tesda_reports' => (clone $confirmedApplications)->whereDoesntHave(
                'competencyReportSubmission',
                fn ($query) => $query->where('status', 'submitted')
            )->count(),
            'ready_for_scheduling' => (clone $confirmedApplications)
                ->with(['institution', 'course'])
                ->whereNull('training_batch_id')
                ->latest('confirmed_at')
                ->take(10)
                ->get(),
            'upcoming_deadlines' => TrainingBatch::query()
                ->with(['institution', 'course'])
                ->whereIn('institution_id', $institutionIds)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->whereBetween('ends_at', [today()->toDateString(), today()->addDays(30)->toDateString()])
                ->orderBy('ends_at')
                ->take(8)
                ->get(),
        ];
    }

    return [
        'role' => $user->role,
        'focal_overview' => $focalOverview,
        'administrator_overview' => $administratorOverview,
        'total_trainees' => $total,
        'pending_intakes' => (clone $applications)->whereIn('application_status', ['submitted', 'under_review', 'returned_for_revision'])->count(),
        'incomplete_records' => (clone $applications)->where('record_completeness', '<', 100)->count(),
        'upcoming_batches' => TrainingBatch::query()->whereIn('institution_id', $institutionIds)->where('status', 'upcoming')->count(),
        'readiness' => $total === 0 ? 0 : (int) round(($complete / $total) * 100),
        'batches' => TrainingBatch::query()->with('course')->whereIn('institution_id', $institutionIds)->orderBy('starts_at')->take(6)->get(),
        'activities' => ActivityLog::query()->whereIn('institution_id', $institutionIds)->latest()->take(6)->get(),
        'recent_submissions' => $user->hasRole('registrar')
            ? EnrollmentApplication::query()->with(['course', 'institution', 'trainingBatch'])
                ->whereIn('institution_id', $institutionIds)->whereNotNull('submitted_at')->whereNull('archived_at')
                ->latest('submitted_at')->take(15)->get()
            : collect(),
        'pending_account_requests' => $user->hasRole('registrar')
            ? User::query()->where('account_status', 'pending')->whereHas('institutions', fn ($query) => $query->whereIn('institutions.id', $institutionIds))->count()
            : 0,
    ];
});

$refresh = function (): void {
    unset($this->dashboard);
    Flux::toast(variant: 'success', text: 'Dashboard data refreshed.');
};
?>

@php($data = $this->dashboard)

<div class="mx-auto max-w-7xl space-y-6">
    <a data-user-guide-card href="{{ route('user-guide') }}" wire:navigate class="group relative block overflow-hidden rounded-2xl bg-gradient-to-r from-[#102f68] via-[#174b83] to-cyan-700 p-5 text-white shadow-md transition hover:-translate-y-0.5 hover:shadow-lg sm:p-6">
        <div class="pointer-events-none absolute -right-6 -top-12 h-40 w-40 rounded-full border-[20px] border-white/10"></div>
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-4">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-amber-400 text-2xl font-black text-[#102f68]">?</span>
                <span>
                    <span class="block text-xs font-bold uppercase tracking-[0.18em] text-cyan-100">New here? Start here.</span>
                    <span class="mt-1 block text-xl font-black">User Guide & Role Handbook</span>
                    <span class="mt-1 block max-w-2xl text-sm leading-6 text-blue-100">Learn what your role can do, follow the right workflow, and find answers to common questions.</span>
                </span>
            </div>
            <span class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-white px-4 py-3 text-sm font-extrabold text-[#102f68] transition group-hover:bg-amber-300">Open User Guide <span aria-hidden="true">â†’</span></span>
        </div>
    </a>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-cyan-600">TESDAConnect workspace</p>
            <flux:heading size="xl" class="mt-1 text-3xl font-black tracking-tight">Good day, {{ auth()->user()->name }}.</flux:heading>
            <flux:text class="mt-1 text-base text-slate-500">Here is your live overview of TESDA training operations.</flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button variant="ghost" icon="arrow-path" wire:click="refresh">Refresh data</flux:button>
            @if (auth()->user()->hasRole('administrator', 'registrar'))
                <flux:button variant="primary" :href="route('enrollments.index')" wire:navigate icon="clipboard-document-list">View records</flux:button>
            @endif
        </div>
    </div>

    @if (auth()->user()->hasRole('registrar'))
        <section id="recent-submissions" class="space-y-4 scroll-mt-20" aria-labelledby="recent-submissions-heading">
            <div class="flex flex-wrap items-end justify-between gap-3"><div><flux:heading id="recent-submissions-heading" size="lg">Recent Submissions</flux:heading><flux:text class="mt-1">The newest 15 submitted enrollment records across your assigned institutions.</flux:text></div><div class="flex gap-2"><flux:button variant="outline" :href="route('registrar.account-requests')" wire:navigate>Account requests ({{ $data['pending_account_requests'] }})</flux:button><flux:button variant="primary" :href="route('enrollments.index', ['status' => 'submitted'])" wire:navigate>Review enrollments</flux:button></div></div>
            <flux:card class="bg-white p-0 shadow-sm"><div class="overflow-x-auto"><table class="w-full min-w-[800px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Applicant</th><th class="px-5 py-3">Course</th><th class="px-5 py-3">Institution / Batch</th><th class="px-5 py-3">Submitted</th><th class="px-5 py-3">Status</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse($data['recent_submissions'] as $submission)<tr wire:key="recent-submission-{{ $submission->id }}"><td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ $submission->learner_name }}</p><p class="text-xs text-slate-500">{{ $submission->reference_no }}</p></td><td class="px-5 py-4">{{ $submission->course?->name ?? 'Course pending' }}</td><td class="px-5 py-4">{{ $submission->institution?->code }}<p class="mt-1 text-xs text-slate-500">{{ $submission->trainingBatch?->name ?? 'Unassigned' }}</p></td><td class="px-5 py-4">{{ $submission->submitted_at?->format('M j, Y g:i A') }}</td><td class="px-5 py-4"><span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-800">{{ str($submission->application_status)->headline() }}</span></td></tr>@empty<tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No submitted applications yet.</td></tr>@endforelse</tbody></table></div></flux:card>
        </section>
    @endif

    @if ($data['focal_overview'])
        <section class="space-y-5" aria-labelledby="focal-overview-heading">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <flux:heading id="focal-overview-heading" size="lg">UTPRAS / Focal Person Overview</flux:heading>
                    <flux:text class="mt-1">Live operations across your assigned institution(s).</flux:text>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($data['focal_overview']['assigned_institutions'] as $assignedInstitution)
                        <span class="rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1.5 text-xs font-bold text-cyan-800" wire:key="focal-institution-{{ $assignedInstitution->id }}">
                            {{ $assignedInstitution->code }} · {{ $assignedInstitution->name }}
                        </span>
                    @endforeach
                    @if ($data['focal_overview']['assigned_institutions']->isEmpty())
                        <span class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-800">No institution assigned</span>
                    @endif
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['Confirmed trainees', $data['focal_overview']['confirmed_trainees'], 'Confirmed student records', 'border-l-[#173b78]'],
                    ['Pending encodings', $data['focal_overview']['pending_encodings'], 'Confirmed trainees with no competency status saved yet', 'border-l-amber-500'],
                    ['Progress summaries pending', $data['focal_overview']['pending_tesda_reports'], 'Confirmed trainee reports not yet marked submitted', 'border-l-cyan-600'],
                    ['Ready for scheduling', $data['focal_overview']['ready_for_scheduling']->count(), 'Confirmed trainees without a batch', 'border-l-emerald-500'],
                ] as [$label, $value, $detail, $border])
                    <flux:card class="border-l-4 {{ $border }} bg-white p-5 shadow-sm">
                        <p class="text-sm font-semibold text-slate-600">{{ $label }}</p>
                        <p class="mt-2 text-3xl font-black text-[#102f68]">{{ $value }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $detail }}</p>
                    </flux:card>
                @endforeach
            </div>
            <div class="grid gap-5 xl:grid-cols-2">
                <flux:card class="bg-white p-0 shadow-sm">
                    <div class="flex items-start justify-between gap-3 border-b border-slate-100 p-5"><div><flux:heading size="md">Recent enrollments ready for scheduling</flux:heading><flux:text class="mt-1">Confirmed trainees that have not yet been assigned to a batch.</flux:text></div><flux:button size="sm" variant="outline" :href="route('schedules.index')" wire:navigate>Open Scheduling</flux:button></div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[520px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Trainee</th><th class="px-4 py-3">Course</th><th class="px-4 py-3">Institution</th><th class="px-4 py-3">Confirmed</th></tr></thead><tbody class="divide-y divide-slate-100">
                    @forelse($data['focal_overview']['ready_for_scheduling'] as $readyApplication)<tr wire:key="ready-scheduling-{{ $readyApplication->id }}"><td class="px-4 py-3"><p class="font-semibold text-slate-900">{{ $readyApplication->learner_name }}</p><p class="text-xs text-slate-500">{{ $readyApplication->scholar_id ?: $readyApplication->reference_no }}</p></td><td class="px-4 py-3">{{ $readyApplication->course?->name ?? 'Course pending' }}</td><td class="px-4 py-3">{{ $readyApplication->institution?->code }}</td><td class="px-4 py-3">{{ $readyApplication->confirmed_at?->format('M j, Y') ?? '—' }}</td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No confirmed trainees are waiting for batch assignment.</td></tr>@endforelse
                    </tbody></table></div>
                </flux:card>
                <flux:card class="bg-white p-0 shadow-sm">
                    <div class="border-b border-slate-100 p-5"><flux:heading size="md">Upcoming batch end dates</flux:heading><flux:text class="mt-1">Use these dates to prepare TESDA reports and completion checks. Explicit report due dates are not stored in this version.</flux:text></div>
                    <div class="divide-y divide-slate-100">
                        @forelse($data['focal_overview']['upcoming_deadlines'] as $deadlineBatch)<div class="flex items-start justify-between gap-3 p-4"><div><p class="font-semibold text-slate-900">{{ $deadlineBatch->name }}</p><p class="text-xs text-slate-500">{{ $deadlineBatch->institution?->code }} · {{ $deadlineBatch->course?->name }}</p></div><span class="shrink-0 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-800">Ends {{ $deadlineBatch->ends_at?->format('M j, Y') }}</span></div>@empty<div class="p-8 text-center text-sm text-slate-500">No batch end dates are due within the next 30 days.</div>@endforelse
                    </div>
                </flux:card>
            </div>
        </section>
    @endif

    @if ($data['administrator_overview'])
        <section class="space-y-5" aria-labelledby="system-overview-heading">
            <div>
                <flux:heading id="system-overview-heading" size="lg">System overview</flux:heading>
                <flux:text class="mt-1">Administrator quick stats across BST and MIF.</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ([
                    ['Active users', $data['administrator_overview']['active_users'], 'Accounts with Active status'],
                    ['Confirmed enrollees', $data['administrator_overview']['confirmed_enrollees'], 'Enrollment applications confirmed'],
                    ['Pending tasks', $data['administrator_overview']['pending_tasks'], 'Submitted, under review, or returned'],
                    ["This month’s intake", $data['administrator_overview']['this_month_intake'], 'Applications submitted this month'],
                    ['Pending verifications', $data['administrator_overview']['pending_verifications'], 'Submitted or forwarded for review'],
                    ['Schedule deadlines', $data['administrator_overview']['upcoming_schedule_deadlines'], 'Batch end dates within the next 30 days'],
                ] as [$label, $value, $detail])
                    <flux:card class="border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-semibold text-slate-600">{{ $label }}</p>
                        <p class="mt-2 text-3xl font-black tracking-tight text-[#102f68]">{{ $value }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ $detail }}</p>
                    </flux:card>
                @endforeach
            </div>

            <div>
                <flux:heading size="lg">BST vs MIF comparison</flux:heading>
                <flux:text class="mt-1">Enrollment records, monthly intake, pending work, and completion rate.</flux:text>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ([['code' => 'BST', 'tone' => 'blue'], ['code' => 'MIF', 'tone' => 'emerald']] as $institutionView)
                    @php($institutionStats = $data['administrator_overview']['comparison'][$institutionView['code']])
                    <flux:card class="border-t-4 {{ $institutionView['tone'] === 'blue' ? 'border-t-blue-600' : 'border-t-emerald-600' }} bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-widest {{ $institutionView['tone'] === 'blue' ? 'text-blue-700' : 'text-emerald-700' }}">{{ $institutionView['code'] }}</p>
                                <flux:heading size="md" class="mt-1">{{ $institutionStats['name'] }}</flux:heading>
                            </div>
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ $institutionStats['completion_rate'] }}% complete</span>
                        </div>
                        <dl class="mt-5 grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">Enrollment records</dt><dd class="mt-1 text-2xl font-black text-slate-800">{{ $institutionStats['enrollment_records'] }}</dd></div>
                            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">This month’s intake</dt><dd class="mt-1 text-2xl font-black text-slate-800">{{ $institutionStats['monthly_intake'] }}</dd></div>
                            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">Pending applications</dt><dd class="mt-1 text-2xl font-black text-slate-800">{{ $institutionStats['pending'] }}</dd></div>
                            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">Confirmed enrollees</dt><dd class="mt-1 text-2xl font-black text-slate-800">{{ $institutionStats['confirmed'] }}</dd></div>
                        </dl>
                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $institutionView['tone'] === 'blue' ? 'bg-blue-600' : 'bg-emerald-600' }}" style="width: {{ $institutionStats['completion_rate'] }}%"></div></div>
                        <p class="mt-2 text-xs text-slate-400">Completion rate = completed certificates ÷ confirmed enrollees.</p>
                    </flux:card>
                @endforeach
            </div>
        </section>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ([
            ['Total Trainees', $data['total_trainees'], 'Active learner records', 'border-l-[#173b78]', '♟'],
            ['Pending Intake Forms', $data['pending_intakes'], 'Needs staff review', 'border-l-amber-500', '▤'],
            ['Incomplete Records', $data['incomplete_records'], 'Documents or fields missing', 'border-l-rose-500', '⚠'],
            ['Upcoming Batches', $data['upcoming_batches'], 'Ready for coordination', 'border-l-emerald-500', '□'],
            ['Report Readiness', $data['readiness'].'%', 'Based on complete records', 'border-l-cyan-500', '◴'],
        ] as [$label, $value, $detail, $border, $icon])
            <flux:card class="border-l-4 {{ $border }} bg-white p-5 shadow-sm">
                <span class="grid size-10 place-items-center rounded-xl bg-slate-50 text-lg text-slate-500">{{ $icon }}</span>
                <p class="mt-4 text-4xl font-black tracking-tight text-[#102f68]">{{ $value }}</p>
                <p class="mt-1 text-sm font-semibold text-slate-700">{{ $label }}</p>
                <p class="mt-2 text-xs text-slate-400">{{ $detail }}</p>
            </flux:card>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.8fr)_minmax(20rem,.85fr)]">
        <flux:card class="bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between"><div><flux:heading size="lg">Trainee enrollment per batch</flux:heading><flux:text class="mt-1">Current records assigned to each training batch</flux:text></div><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">Live data</span></div>
            <div class="mt-8 flex h-64 items-end gap-3 border-b border-slate-100 px-2 pb-7">
                @forelse ($data['batches'] as $batch)
                    @php($enrolled = $batch->applications()->count())
                    @php($height = max(12, min(100, $batch->capacity ? ($enrolled / $batch->capacity) * 100 : 0)))
                    <div class="flex min-w-0 flex-1 flex-col items-center gap-2"><span class="text-sm font-bold text-slate-600">{{ $enrolled }}</span><div class="w-full max-w-16 rounded-t-xl bg-gradient-to-t from-[#173b78] to-cyan-500 transition-all" style="height: {{ $height }}%"></div><span class="truncate text-center text-xs text-slate-500">{{ $batch->name }}</span></div>
                @empty
                    <div class="grid h-full w-full place-items-center text-sm text-slate-400">No batches have been created yet.</div>
                @endforelse
            </div>
        </flux:card>

        <flux:card class="bg-white p-6 shadow-sm">
            <flux:heading size="lg">Record completeness</flux:heading>
            <div class="mt-7 grid place-items-center"><div class="grid size-48 place-items-center rounded-full" style="background: conic-gradient(#22c55e {{ $data['readiness'] }}%, #fee2e2 0)"><div class="grid size-32 place-items-center rounded-full bg-white text-center"><span class="text-4xl font-black text-[#102f68]">{{ $data['readiness'] }}%</span><span class="-mt-5 text-xs text-slate-400">complete</span></div></div></div>
            <div class="mt-7 grid grid-cols-2 gap-3 text-sm"><div class="rounded-xl bg-emerald-50 p-3"><p class="font-bold text-emerald-700">Complete</p><p class="mt-1 text-xs text-emerald-600">Ready for reports</p></div><div class="rounded-xl bg-rose-50 p-3"><p class="font-bold text-rose-700">Action needed</p><p class="mt-1 text-xs text-rose-600">Complete missing data</p></div></div>
        </flux:card>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_1fr]">
        <flux:card class="bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between"><flux:heading size="lg">Recent activity</flux:heading><a href="{{ auth()->user()->hasRole('administrator') ? route('system.activity') : route('reports.index') }}" class="text-sm font-semibold text-blue-700" wire:navigate>View all</a></div>
            <div class="mt-4 divide-y divide-slate-100">
                @forelse ($data['activities'] as $activity)
                    <div class="flex gap-3 py-4"><span class="mt-0.5 grid size-8 place-items-center rounded-full bg-blue-50 text-sm">●</span><div><p class="text-sm font-semibold text-slate-700">{{ $activity->description }}</p><p class="mt-1 text-xs text-slate-400">{{ $activity->created_at->diffForHumans() }}</p></div></div>
                @empty
                    <p class="py-8 text-center text-sm text-slate-400">No activity has been logged yet.</p>
                @endforelse
            </div>
        </flux:card>

        <flux:card class="bg-white p-6 shadow-sm">
            <flux:heading size="lg">Quick actions</flux:heading>
            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @if (auth()->user()->hasRole('administrator', 'registrar'))
                    <flux:button variant="primary" :href="route('enrollments.index')" wire:navigate icon="user-plus">Review intake</flux:button>
                @endif
                @if (auth()->user()->hasRole('administrator', 'registrar', 'focal_person'))
                    <flux:button variant="filled" :href="route('schedules.index')" wire:navigate icon="calendar-days">Manage schedules</flux:button>
                    <flux:button variant="filled" :href="route('reports.index')" wire:navigate icon="chart-bar">Open reports</flux:button>
                @endif
                @if (auth()->user()->hasRole('scholar'))
                    <flux:button variant="primary" :href="route('learner.portal')" wire:navigate icon="academic-cap">My learning portal</flux:button>
                @endif
            </div>
            <div class="mt-8 border-t border-slate-100 pt-5"><p class="text-sm font-bold text-slate-700">Batch status overview</p><div class="mt-4 space-y-4">@forelse ($data['batches'] as $batch)<div><div class="mb-1 flex justify-between text-xs"><span class="font-medium text-slate-600">{{ $batch->name }}</span><span class="font-bold text-slate-500">{{ $batch->status === 'in_training' ? 'In training' : 'Upcoming' }}</span></div><div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $batch->status === 'in_training' ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $batch->status === 'in_training' ? '72' : '35' }}%"></div></div></div>@empty <p class="text-sm text-slate-400">No batch data yet.</p>@endforelse</div></div>
        </flux:card>
    </div>
</div>
