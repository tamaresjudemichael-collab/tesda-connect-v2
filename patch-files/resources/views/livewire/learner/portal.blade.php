
<?php

use App\Models\Announcement;
use App\Models\AnnouncementView;
use App\Models\AttendanceRecord;
use App\Models\CompetencyUnit;
use App\Models\EnrollmentApplication;
use Flux\Flux;
use App\Models\LearnerDocument;
use App\Models\TrainingCertificate;
use App\Support\TrainingCompletionService;
use function Livewire\Volt\{computed, layout, mount, title};

layout('layouts::app');
title('Student / Scholar Dashboard');

$application = computed(fn () => EnrollmentApplication::query()
    ->with(['course', 'trainingBatch', 'institution'])
    ->where('learner_user_id', auth()->id())
    ->latest()
    ->first());

$visibleAnnouncements = computed(function () {
    $application = $this->application;

    return Announcement::query()
        ->with(['views' => fn ($views) => $views->where('user_id', auth()->id())])
        ->whereNotNull('published_at')
        ->where('published_at', '<=', now())
        ->whereNull('archived_at')
        ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
        ->where(fn ($query) => $query->where('audience', 'all')->orWhere('audience', 'scholar'))
        ->where(function ($query) use ($application): void {
            $query->whereNull('institution_id');
            if ($application?->institution_id) {
                $query->orWhere('institution_id', $application->institution_id);
            }
        })
        ->latest('published_at')
        ->get();
});

// Record unique announcement impressions for viewership reporting. This does not mark
// them as read; unread status is tracked separately until the learner clicks Mark as read.
mount(function (): void {
    $user = auth()->user();

    if (! $user || ! $user->hasRole('applicant', 'scholar')) {
        return;
    }

    foreach ($this->visibleAnnouncements as $announcement) {
        AnnouncementView::query()->firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['viewed_at' => now(), 'read_at' => null]
        );
    }
});

$announcements = computed(fn () => $this->visibleAnnouncements);

$unreadAnnouncementCount = computed(fn () => $this->visibleAnnouncements
    ->filter(fn ($announcement) => $announcement->views->isEmpty() || $announcement->views->first()?->read_at === null)
    ->count());

$upcomingDeadlines = computed(fn () => $this->visibleAnnouncements
    ->filter(fn ($announcement) => $announcement->deadline_at && $announcement->deadline_at->isFuture())
    ->sortBy('deadline_at')
    ->take(5)
    ->values());

$nextClassBatch = computed(function () {
    $batch = $this->application?->trainingBatch;

    if (! $batch || ! $batch->next_class_at || $batch->next_class_at->isPast()
        || in_array($batch->status, ['completed', 'cancelled'], true)) {
        return null;
    }

    return $batch;
});

$studentStatus = computed(function () {
    $record = $this->application;

    if (! $record || $record->application_status !== 'confirmed') {
        return 'Not Started';
    }

    $certificate = $this->trainingCertificate;
    if ($certificate?->status === 'completed') {
        return 'Completed';
    }
    if ($certificate?->status === 'not_completed' || $record->trainingBatch?->status === 'completed') {
        return 'Not Completed';
    }
    if ($record->trainingBatch?->status === 'in_training') {
        return 'In Training';
    }

    return 'Not Started';
});

$markAnnouncementRead = function (int $announcementId): void {
    $user = auth()->user();
    abort_unless($user && $user->hasRole('applicant', 'scholar'), 403);

    $announcement = $this->visibleAnnouncements->firstWhere('id', $announcementId);
    abort_unless($announcement, 404, 'This announcement is not available to your account.');

    $view = AnnouncementView::query()->firstOrCreate(
        ['announcement_id' => $announcement->id, 'user_id' => $user->id],
        ['viewed_at' => now(), 'read_at' => null]
    );

    if ($view->read_at === null) {
        $view->forceFill(['read_at' => now()])->save();
    }

    unset($this->visibleAnnouncements, $this->announcements, $this->unreadAnnouncementCount);
    Flux::toast(variant: 'success', text: 'Announcement marked as read.');
};

$documents = computed(fn () => LearnerDocument::query()
    ->where('user_id', auth()->id())
    ->latest()
    ->get());

$competencyUnits = computed(function () {
    $record = $this->application;

    if (! $record || $record->application_status !== 'confirmed' || ! $record->course_id) {
        return collect();
    }

    return CompetencyUnit::query()
        ->where('course_id', $record->course_id)
        ->with(['tasks' => fn ($query) => $query
            ->where('chart_type', 'progress')
            ->with(['progressRecords' => fn ($progressQuery) => $progressQuery
                ->where('enrollment_application_id', $record->id)])])
        ->orderBy('sort_order')
        ->get();
});

$competencySummary = computed(function () {
    $tasks = $this->competencyUnits->flatMap(fn ($unit) => $unit->tasks);
    $total = $tasks->count();
    $competent = $tasks->filter(fn ($task) => in_array(
        $task->progressRecords->first()?->status,
        ['competent', 'submitted_output_correct'],
        true
    ))->count();
    $assessed = $tasks->filter(fn ($task) => ! in_array(
        $task->progressRecords->first()?->status ?? 'not_assessed',
        ['not_assessed', null],
        true
    ))->count();

    return [
        'total' => $total,
        'competent' => $competent,
        'assessed' => $assessed,
        'remaining' => max(0, $total - $competent),
        'percent' => $total > 0 ? (int) round(($competent / $total) * 100) : 0,
    ];
});

$attendanceRecords = computed(function () {
    $record = $this->application;

    if (! $record || $record->application_status !== 'confirmed') {
        return collect();
    }

    return AttendanceRecord::query()
        ->where('enrollment_application_id', $record->id)
        ->with('recorder')
        ->orderByDesc('attendance_date')
        ->take(10)
        ->get();
});

$attendanceSummary = computed(function () {
    $record = $this->application;

    if (! $record || $record->application_status !== 'confirmed') {
        return ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0];
    }

    $counts = AttendanceRecord::query()
        ->where('enrollment_application_id', $record->id)
        ->selectRaw('status, COUNT(*) as aggregate')
        ->groupBy('status')
        ->pluck('aggregate', 'status');

    return [
        'present' => (int) ($counts['present'] ?? 0),
        'absent' => (int) ($counts['absent'] ?? 0),
        'late' => (int) ($counts['late'] ?? 0),
        'total' => (int) $counts->sum(),
    ];
});

$trainingCertificate = computed(fn () => $this->application
    ? TrainingCertificate::query()->where('enrollment_application_id', $this->application->id)->first()
    : null);

$certificateEligibility = computed(fn () => $this->application
    ? app(TrainingCompletionService::class)->assess($this->application)
    : ['eligible' => false, 'pending_units' => ['No enrollment application found.'], 'message' => 'No enrollment application found.']);
?>

@php
    // Resolve Volt computed properties once in component context, then use local variables in Blade loops.
    $record = $this->application;
    $portalAnnouncements = $this->announcements;
    $portalDocuments = $this->documents;
    $portalCompetencyUnits = $this->competencyUnits;
    $portalCompetencySummary = $this->competencySummary;
    $portalAttendanceRecords = $this->attendanceRecords;
    $portalAttendanceSummary = $this->attendanceSummary;
    $portalCertificate = $this->trainingCertificate;
    $portalCertificateEligibility = $this->certificateEligibility;
    $studentStatus = $this->studentStatus;
    $nextClassBatch = $this->nextClassBatch;
    $upcomingDeadlines = $this->upcomingDeadlines;
    $unreadAnnouncementCount = $this->unreadAnnouncementCount;
    $studentStatusClasses = match ($studentStatus) {
        'In Training' => 'border-violet-200 bg-violet-50 text-violet-800',
        'Completed' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'Not Completed' => 'border-rose-200 bg-rose-50 text-rose-800',
        default => 'border-amber-200 bg-amber-50 text-amber-800',
    };
@endphp

<div class="mx-auto max-w-7xl space-y-7 pb-10">

    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-900 p-6 text-white shadow-xl sm:p-9">
        <div class="relative z-10 max-w-2xl">
            <p class="text-xs font-bold uppercase tracking-[0.25em] text-cyan-300">
                TESDA Connect
            </p>
            <h1 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">
                Student / Scholar Dashboard
            </h1>
            <p class="mt-3 max-w-xl text-sm leading-6 text-blue-100 sm:text-base">
                Check your training status, upcoming class, submission deadlines, announcements, and enrollment requirements.
            </p>
        </div>
        <div class="pointer-events-none absolute -right-10 -top-20 h-64 w-64 rounded-full border border-white/10"></div>
        <div class="pointer-events-none absolute -right-2 -top-12 h-48 w-48 rounded-full border border-white/10"></div>
        <div class="pointer-events-none absolute -bottom-24 right-20 h-52 w-52 rounded-full bg-cyan-400/10 blur-3xl"></div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Student dashboard summary">
        <article class="rounded-2xl border bg-white p-5 shadow-sm {{ $studentStatusClasses }}">
            <p class="text-sm font-semibold opacity-80">My Status</p>
            <p class="mt-4 text-2xl font-black">{{ $studentStatus }}</p>
            @if ($record?->application_status && $record->application_status !== 'confirmed')
                <p class="mt-1 text-xs">Enrollment: {{ str($record->application_status)->headline() }}</p>
            @else
                <p class="mt-1 text-xs">Based on confirmed enrollment and training completion.</p>
            @endif
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-semibold text-slate-500">Next Class</p>
            @if ($nextClassBatch)
                <p class="mt-3 text-lg font-black text-slate-900">{{ $nextClassBatch->next_class_at->format('M d, Y') }}</p>
                <p class="mt-1 text-sm font-semibold text-blue-800">{{ $nextClassBatch->next_class_at->format('h:i A') }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ $nextClassBatch->venue ?: 'Venue not announced' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $nextClassBatch->name }} · {{ $nextClassBatch->code }}</p>
            @else
                <p class="mt-3 text-lg font-black text-slate-900">To be announced</p>
                <p class="mt-1 text-sm text-slate-500">Your Focal Person will update the next class schedule.</p>
                @if ($record?->trainingBatch)
                    <p class="mt-2 text-xs text-slate-500">Batch: {{ $record->trainingBatch->name }}</p>
                @endif
            @endif
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-semibold text-slate-500">Submission Deadlines</p>
            <p class="mt-4 text-2xl font-black text-slate-900">{{ $upcomingDeadlines->count() }}</p>
            <p class="mt-1 text-xs text-slate-500">Upcoming due date(s)</p>
            @if ($upcomingDeadlines->isNotEmpty())
                <p class="mt-3 text-sm font-semibold text-amber-800">Next: {{ $upcomingDeadlines->first()->deadline_at->format('M d, Y h:i A') }}</p>
            @else
                <p class="mt-3 text-sm text-slate-500">No upcoming submission deadline posted.</p>
            @endif
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-semibold text-slate-500">Unread Announcements</p>
            <p class="mt-4 text-2xl font-black {{ $unreadAnnouncementCount > 0 ? 'text-blue-700' : 'text-slate-900' }}">{{ $unreadAnnouncementCount }}</p>
            <p class="mt-1 text-xs text-slate-500">Unread updates for your account</p>
            <a href="#student-announcements" class="mt-3 inline-flex text-sm font-bold text-blue-700 hover:text-cyan-700">View announcements ↓</a>
        </article>
    </section>

    @if ($upcomingDeadlines->isNotEmpty())
        <section class="rounded-2xl border border-amber-200 bg-amber-50/70 p-5 sm:p-6">
            <h2 class="text-lg font-black text-amber-950">Upcoming submission deadlines</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                @foreach ($upcomingDeadlines as $deadline)
                    <article class="rounded-xl border border-amber-100 bg-white p-4" wire:key="student-deadline-{{ $deadline->id }}">
                        <p class="font-bold text-slate-900">{{ $deadline->title }}</p>
                        <p class="mt-1 text-sm text-amber-800">Due {{ $deadline->deadline_at->format('M d, Y h:i A') }}</p>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $deadline->body }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section class="flex flex-col gap-4 rounded-2xl border border-blue-100 bg-blue-50/60 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <div>
            <h2 class="text-lg font-black text-slate-900">Enrollment Submission</h2>
            <p class="mt-1 text-sm text-slate-600">Review the mandatory and conditional documents before submitting your digital TESDA Learner Profile Form.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('learner.requirements') }}" wire:navigate class="inline-flex rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">View Requirements</a>
            <a href="{{ route('learner.registration') }}" wire:navigate class="inline-flex rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800">Submit Enrollment</a>
        </div>
    </section>

    @if ($record)
        <section class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-center">
            <div>
                <p class="text-sm font-medium text-slate-500">Welcome back,</p>
                <h2 class="mt-1 text-2xl font-black tracking-tight text-slate-900">
                    {{ auth()->user()->name }}
                </h2>
                <p class="mt-2 text-sm text-slate-500">
                    Here is the latest information associated with your learner account.
                </p>
            </div>
            <div class="inline-flex w-fit items-center gap-2 rounded-full border px-4 py-2 text-sm font-bold
                {{ $record->application_status === 'confirmed'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                    : 'border-amber-200 bg-amber-50 text-amber-700' }}">
                <span class="h-2 w-2 rounded-full
                    {{ $record->application_status === 'confirmed' ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                {{ str($record->application_status)->headline() }}
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">Enrollment status</p>
                <p class="mt-4 text-xl font-black text-slate-900">
                    {{ str($record->application_status)->headline() }}
                </p>
                <p class="mt-1 text-xs text-slate-500">Current application status</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">Requirements</p>
                <p class="mt-4 text-xl font-black text-slate-900">
                    {{ max(0, min(100, (int) $record->record_completeness)) }}%
                </p>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-emerald-500"
                        style="width: {{ max(0, min(100, (int) $record->record_completeness)) }}%"></div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">Training batch</p>
                <p class="mt-4 break-words text-xl font-black text-slate-900">
                    {{ $record->trainingBatch?->name ?? 'Not assigned' }}
                </p>
                <p class="mt-1 text-xs text-slate-500">Assigned training group</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">Scholar ID</p>
                <p class="mt-4 break-all text-lg font-black text-slate-900">
                    {{ $record->scholar_id ?? 'Pending' }}
                </p>
                <p class="mt-1 text-xs text-slate-500">Learner reference number</p>
            </div>
        </section>





 
<style>
    @@media print {
        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        html,
        body {
            width: 100% !important;
            height: auto !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        body * {
            visibility: hidden !important;
        }

        #scholar-id-card,
        #scholar-id-card * {
            visibility: visible !important;
        }

        #scholar-id-card {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            display: block !important;
            width: 150mm !important;
            max-width: 150mm !important;
            height: auto !important;
            margin: 0 !important;
            padding: 7mm !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
            border-radius: 4mm !important;
            box-shadow: none !important;
            background-image: linear-gradient(135deg, #071b3d, #103e78, #087e9b) !important;
            break-inside: avoid !important;
            page-break-inside: avoid !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        #scholar-id-card * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    }
</style>





        <section class="grid gap-6 xl:grid-cols-2 print:block">
            {{-- Scholar ID Card --}}
            @if ($record->application_status === 'confirmed' && filled($record->scholar_id))
            <div class="overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-slate-200 print:overflow-visible print:rounded-none print:shadow-none print:ring-0">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between print:hidden">
                    <div>
                        <h3 class="font-bold text-slate-900">Scholar ID Card</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Digital learner identification reference
                        </p>
                    </div>

                      
<button
    type="button"
    onclick="window.print()"
    class="inline-flex w-fit items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 print:hidden"
>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        class="h-4 w-4"
        aria-hidden="true"
    >
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M7 14h10v7H7z"/>
    </svg>
    Print Scholar ID
</button>

                </div>

                <div class="p-5 sm:p-6 print:p-0">
                    <div
                        id="scholar-id-card"
                        class="relative isolate mx-auto w-full max-w-xl overflow-hidden rounded-3xl bg-gradient-to-br from-[#071b3d] via-[#103e78] to-[#087e9b] p-6 text-white shadow-xl sm:p-8 print:max-w-none print:rounded-2xl print:shadow-none"
                    >
                        {{-- Decorative background --}}
                        <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full border-[28px] border-white/5"></div>
                        <div class="pointer-events-none absolute -bottom-24 -left-16 h-56 w-56 rounded-full bg-cyan-400/10 blur-3xl"></div>

                        <div class="relative">
                            {{-- Header --}}
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-extrabold uppercase tracking-[0.25em] text-cyan-200">
                                        TESDA Connect
                                    </p>

                                    <h4 class="mt-2 text-2xl font-black tracking-tight sm:text-3xl">
                                        SCHOLAR ID
                                    </h4>

                                    <p class="mt-1 text-xs text-blue-100">
                                        Learner identification reference
                                    </p>
                                </div>

                                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-white/20 bg-white/10 text-lg font-black tracking-tight ring-1 ring-white/10">
                                    TC
                                </div>
                            </div>

                            {{-- Institution badge --}}
                            <div class="mt-6 inline-flex max-w-full items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5">
                                <span class="h-2 w-2 shrink-0 rounded-full bg-cyan-300"></span>
                                <span class="truncate text-xs font-semibold text-blue-50">
                                    {{ $record->institution?->name ?? 'Institution not assigned' }}
                                </span>
                            </div>

                            {{-- Learner details --}}
                            <div class="mt-7 border-t border-white/20 pt-5">
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-cyan-200">
                                    Learner name
                                </p>

                                <p class="mt-2 break-words text-xl font-extrabold leading-tight sm:text-2xl">
                                    {{ auth()->user()->name }}
                                </p>

                                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-blue-200">
                                            Scholar ID number
                                        </p>

                                        <p class="mt-2 break-all font-mono text-base font-bold tracking-wide text-white">
                                            {{ $record->scholar_id ?? 'Not yet issued' }}
                                        </p>
                                    </div>

                                    <div class="min-w-0">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-blue-200">
                                            Account status
                                        </p>

                                        <p class="mt-2 text-sm font-bold text-white">
                                            {{ str(auth()->user()->account_status ?? 'active')->headline() }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {{-- Footer --}}
                            <div class="mt-6 flex items-end justify-between gap-4 border-t border-white/20 pt-4">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-blue-200">
                                        Institution code
                                    </p>

                                    <p class="mt-1 break-all text-sm font-bold">
                                        {{ $record->institution?->code ?? 'Not assigned' }}
                                    </p>
                                </div>

                                <p class="max-w-40 text-right text-[9px] leading-4 text-blue-100">
                                    Digital portal reference only.
                                    Subject to institution verification.
                                </p>
                            </div>
                        </div>
                    </div>

                    <p class="mx-auto mt-4 max-w-xl text-xs leading-5 text-slate-400 print:hidden">
                        This digital reference is not a substitute for an official physical ID.
                    </p>
                </div>
            </div>
            @endif
            {{-- Course Information --}}
            <div class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7 xl:mt-0 print:hidden">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">
                    Your training
                </p>

                <h3 class="mt-2 text-xl font-black text-slate-900">
                    Course information
                </h3>

                <div class="mt-6 space-y-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                            Qualification
                        </p>

                        <p class="mt-1 font-bold text-slate-800">
                            {{ $record->course?->name ?? 'Training course pending' }}
                        </p>

                        @if ($record->course?->qualification_level)
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $record->course->qualification_level }}
                            </p>
                        @endif
                    </div>

                    <div class="border-t border-slate-100 pt-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                            Institution
                        </p>

                        <p class="mt-1 font-bold text-slate-800">
                            {{ $record->institution?->name ?? 'Not assigned' }}
                        </p>

                        @if ($record->institution?->code)
                            <p class="mt-1 text-sm text-slate-500">
                                Institution code: {{ $record->institution->code }}
                            </p>
                        @endif
                    </div>

                    <div class="border-t border-slate-100 pt-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                            Training start date
                        </p>

                        <p class="mt-1 font-bold text-slate-800">
                            {{ $record->trainingBatch?->starts_at?->format('F d, Y') ?? 'To be announced' }}
                        </p>
                    </div>

                    <div class="border-t border-slate-100 pt-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                            Application reference
                        </p>

                        <p class="mt-1 break-all font-mono text-sm font-bold text-slate-800">
                            {{ $record->reference_no }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

 
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <h3 class="text-xl font-black text-slate-900">Learning progress</h3>
            <p class="mt-1 text-sm text-slate-500">
                Track registration and training preparation. This section does not display grades or assessment scores.
            </p>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 p-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 font-bold text-emerald-700">01</div>
                    <h4 class="mt-4 font-bold text-slate-800">Learner registration</h4>
                    <p class="mt-2 text-sm text-slate-500">Registration record and listed requirements.</p>
                    <span class="mt-4 inline-flex rounded-full px-3 py-1 text-xs font-bold {{ (int) $record->record_completeness === 100 ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                        {{ (int) $record->record_completeness === 100 ? 'Complete' : 'In progress' }}
                    </span>
                </div>

                <div class="rounded-2xl border border-slate-200 p-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 font-bold text-blue-700">02</div>
                    <h4 class="mt-4 font-bold text-slate-800">Training orientation</h4>
                    <p class="mt-2 text-sm text-slate-500">Check for your assigned batch and schedule.</p>
                    <span class="mt-4 inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $record->trainingBatch ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600' }}">
                        {{ $record->trainingBatch ? 'Batch assigned' : 'Awaiting schedule' }}
                    </span>
                </div>

                <div class="rounded-2xl border border-slate-200 p-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 font-bold text-violet-700">03</div>
                    <h4 class="mt-4 font-bold text-slate-800">Competency tasks</h4>
                    <p class="mt-2 text-sm text-slate-500">Training activity status depends on your assigned batch.</p>
                    <span class="mt-4 inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $record->trainingBatch?->status === 'in_training' ? 'bg-violet-50 text-violet-700' : 'bg-slate-100 text-slate-600' }}">
                        {{ $record->trainingBatch?->status === 'in_training' ? 'In progress' : 'Not started' }}
                    </span>
                </div>
            </div>
        </section>

        @if ($record->application_status === 'confirmed')
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="text-xl font-black text-slate-900">Training competency progress</h3>
                        <p class="mt-1 text-sm text-slate-500">View-only progress for the competency tasks associated with your enrolled course. No grades or numerical assessment scores are displayed.</p>
                    </div>
                    <span class="inline-flex w-fit rounded-full bg-blue-50 px-3 py-1.5 text-sm font-bold text-blue-700">{{ $portalCompetencySummary['percent'] }}% competent</span>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-semibold text-slate-500">Total tasks</p><p class="mt-1 text-2xl font-black text-slate-900">{{ $portalCompetencySummary['total'] }}</p></div>
                    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-xs font-semibold text-emerald-700">Competent</p><p class="mt-1 text-2xl font-black text-emerald-800">{{ $portalCompetencySummary['competent'] }}</p></div>
                    <div class="rounded-xl bg-amber-50 p-4"><p class="text-xs font-semibold text-amber-700">Assessed</p><p class="mt-1 text-2xl font-black text-amber-800">{{ $portalCompetencySummary['assessed'] }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-semibold text-slate-500">Remaining</p><p class="mt-1 text-2xl font-black text-slate-900">{{ $portalCompetencySummary['remaining'] }}</p></div>
                </div>
                <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $portalCompetencySummary['percent'] }}%"></div></div>

                @if ($portalCompetencyUnits->isEmpty())
                    <div class="mt-5 rounded-xl border border-dashed border-slate-200 p-5 text-sm text-slate-500">No competency tasks are configured for this course yet. Ask your Focal Person to check the course competency setup.</div>
                @else
                    <div class="mt-6 space-y-4">
                        @forelse ($portalCompetencyUnits as $unit)
                            <div class="overflow-hidden rounded-2xl border border-slate-200" wire:key="learner-competency-unit-{{ $unit->id }}">
                                <div class="bg-slate-50 px-4 py-3"><h4 class="font-bold text-slate-900">Unit {{ $unit->unit_number }} — {{ $unit->name }}</h4></div>
                                <div class="divide-y divide-slate-100">
                                    @forelse ($unit->tasks as $task)
                                        @php
                                            $taskStatus = $task->progressRecords->first()?->status ?? 'not_assessed';
                                            $taskLabel = match ($taskStatus) {
                                                'competent', 'submitted_output_correct' => 'Competent',
                                                'not_yet_competent' => 'Not Yet Competent',
                                                default => 'Not Assessed',
                                            };
                                        @endphp
                                        <div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="learner-competency-task-{{ $task->id }}">
                                            <span class="text-sm text-slate-700">{{ $task->name }}</span>
                                            <span class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold {{ $taskLabel === 'Competent' ? 'bg-emerald-50 text-emerald-700' : ($taskLabel === 'Not Yet Competent' ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-600') }}">{{ $taskLabel }}</span>
                                        </div>
                                    @empty
                                        <div class="px-4 py-3 text-sm text-slate-500">No tasks are configured for this unit yet.</div>
                                    @endforelse
                                </div>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-slate-200 p-5 text-sm text-slate-500">No competency units are configured for this course yet.</div>
                        @endforelse
                    </div>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div><h3 class="text-xl font-black text-slate-900">My attendance</h3><p class="mt-1 text-sm text-slate-500">Attendance records saved by authorized training staff.</p></div>
                    <span class="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1.5 text-sm font-bold text-slate-700">{{ $portalAttendanceSummary['total'] }} recorded session(s)</span>
                </div>
                <div class="mt-5 grid grid-cols-3 gap-3">
                    <div class="rounded-xl bg-emerald-50 p-4"><p class="text-xs font-semibold text-emerald-700">Present</p><p class="mt-1 text-2xl font-black text-emerald-800">{{ $portalAttendanceSummary['present'] }}</p></div>
                    <div class="rounded-xl bg-rose-50 p-4"><p class="text-xs font-semibold text-rose-700">Absent</p><p class="mt-1 text-2xl font-black text-rose-800">{{ $portalAttendanceSummary['absent'] }}</p></div>
                    <div class="rounded-xl bg-amber-50 p-4"><p class="text-xs font-semibold text-amber-700">Late</p><p class="mt-1 text-2xl font-black text-amber-800">{{ $portalAttendanceSummary['late'] }}</p></div>
                </div>
                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Remarks</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($portalAttendanceRecords as $attendance)
                                <tr wire:key="learner-attendance-{{ $attendance->id }}"><td class="px-4 py-3 font-medium text-slate-800">{{ $attendance->attendance_date?->format('M d, Y') }}</td><td class="px-4 py-3"><span class="inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $attendance->status === 'present' ? 'bg-emerald-50 text-emerald-700' : ($attendance->status === 'absent' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">{{ str($attendance->status)->headline() }}</span></td><td class="px-4 py-3 text-slate-600">{{ $attendance->remarks ?: '—' }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">No attendance records have been saved yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="text-xl font-black text-slate-900">Certification & Completion</h3>
                        <p class="mt-1 text-sm text-slate-500">Your certificate status is based on verified training completion. Grades and numerical scores are never shown.</p>
                    </div>
                    @if ($portalCertificate?->status === 'completed')
                        <span class="inline-flex w-fit rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700">READY</span>
                    @elseif ($portalCertificate?->status === 'not_completed')
                        <span class="inline-flex w-fit rounded-full bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-800">NOT COMPLETED</span>
                    @elseif ($portalCertificateEligibility['eligible'])
                        <span class="inline-flex w-fit rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700">READY — AWAITING ISSUANCE</span>
                    @else
                        <span class="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">NOT ELIGIBLE YET</span>
                    @endif
                </div>

                @if ($portalCertificate?->status === 'completed')
                    <div class="mt-4 rounded-xl border border-emerald-100 bg-emerald-50 p-4">
                        <p class="text-sm font-bold text-emerald-900">Certificate of Training Completion</p>
                        <p class="mt-1 text-xs text-emerald-800">Certificate No.: {{ $portalCertificate->certificate_number }}</p>
                        <a href="{{ route('certificates.print', $portalCertificate) }}" target="_blank" class="mt-3 inline-flex rounded-lg bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-800">View / Print Certificate</a>
                    </div>
                @elseif ($portalCertificate?->status === 'not_completed')
                    <p class="mt-4 text-sm text-slate-700">Your training is marked Not Completed. Check with your Focal Person about the remaining requirements.</p>
                    @if (is_array($portalCertificate->pending_units) && count($portalCertificate->pending_units))
                        <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-slate-600">
                            @foreach ($portalCertificate->pending_units as $pendingUnit)
                                <li>{{ $pendingUnit }}</li>
                            @endforeach
                        </ul>
                    @endif
                @else
                    @if ($portalCertificateEligibility['eligible'])
                        <p class="mt-4 text-sm text-blue-800">Your completion requirements appear complete. Your Focal Person can now review and issue your certificate.</p>
                    @else
                        <p class="mt-4 text-sm text-slate-600">Your certificate is not available yet. Your Focal Person must finalize your competency units and verify training completion.</p>
                        @if (count($portalCertificateEligibility['pending_units']))
                            <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-slate-600">
                                @foreach ($portalCertificateEligibility['pending_units'] as $pendingUnit)
                                    <li>{{ $pendingUnit }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                @endif
            </section>
        @endif
    @else
        <section class="rounded-3xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50 text-2xl font-black text-blue-700">
                TC
            </div>
            <h2 class="mt-5 text-xl font-black text-slate-900">No learner application yet</h2>
            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                Once you submit your registration form, your learner information,
                enrollment status, and schedule will appear here.
            </p>
            <flux:button
                class="mt-6"
                variant="primary"
                :href="route('learner.registration')"
                wire:navigate
            >
                Complete registration
            </flux:button>
        </section>
    @endif

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-xl font-black text-slate-900">My documents</h3>
                <p class="mt-1 text-sm text-slate-500">Review the status of your submitted requirements.</p>
            </div>
            <a
                href="{{ route('learner.registration') }}"
                wire:navigate
                class="text-sm font-bold text-blue-700 hover:text-cyan-700"
            >
                Upload or update documents →
            </a>
        </div>

        <div class="mt-5 space-y-3">
            @forelse ($portalDocuments as $document)
                <div class="flex flex-col gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="break-words font-bold text-slate-800">{{ $document->original_name }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ str($document->document_type)->headline() }}
                            · {{ number_format($document->size / 1024, 0) }} KB
                        </p>
                        @if ($document->status === 'rejected' && filled($document->remarks))
                            <p class="mt-2 text-sm leading-5 text-rose-700">
                                <span class="font-semibold">Reason for rejection:</span>
                                {{ $document->remarks }}
                            </p>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col items-start gap-2 sm:flex-row sm:items-center">
                        <span class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold {{ $document->status === 'verified' ? 'bg-emerald-50 text-emerald-700' : ($document->status === 'rejected' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">
                            {{ str($document->status)->headline() }}
                        </span>
                        <a
                            href="{{ route('learner-documents.download', $document->id) }}"
                            class="inline-flex w-fit items-center justify-center rounded-lg bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                        >
                            Download
                        </a>
                    </div>
                </div>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-200 px-5 py-8 text-center">
                    <p class="font-semibold text-slate-700">No documents uploaded yet</p>
                    <p class="mt-1 text-sm text-slate-500">Your submitted requirements will appear here.</p>
                </div>
            @endforelse
        </div>

        <p class="mt-4 text-xs leading-5 text-slate-400">
            Access to private documents should be limited to you and authorized staff.
        </p>
    </section>

    <section id="student-announcements" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="text-xl font-black text-slate-900">Announcements</h3>
            <span class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold {{ $unreadAnnouncementCount > 0 ? 'bg-blue-50 text-blue-800' : 'bg-slate-100 text-slate-600' }}">{{ $unreadAnnouncementCount }} unread</span>
        </div>
        <p class="mt-1 text-sm text-slate-500">Updates published for learners and scholars.</p>

        <div class="mt-5 space-y-3">
            @forelse ($portalAnnouncements as $announcement)
                @php($announcementRead = $announcement->views->contains(fn ($view) => $view->read_at !== null))
                <article class="rounded-2xl border p-5 {{ $announcementRead ? 'border-slate-100 bg-white' : 'border-blue-200 bg-blue-50/40' }}" wire:key="learner-announcement-{{ $announcement->id }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="font-bold text-slate-900">{{ $announcement->title }}</h4>
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $announcementRead ? 'bg-slate-100 text-slate-600' : 'bg-blue-100 text-blue-800' }}">{{ $announcementRead ? 'Read' : 'Unread' }}</span>
                            </div>
                            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ $announcement->body }}</p>
                            <p class="mt-3 text-xs text-slate-400">
                                Published {{ $announcement->published_at?->diffForHumans() ?? 'recently' }}
                                @if ($announcement->deadline_at) · Submission deadline {{ $announcement->deadline_at->format('M d, Y h:i A') }} @endif
                            </p>
                        </div>
                        @unless ($announcementRead)
                            <button type="button" wire:click="markAnnouncementRead({{ $announcement->id }})" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-blue-700 px-3 py-2 text-sm font-bold text-white hover:bg-blue-800">Mark as read</button>
                        @endunless
                    </div>
                </article>
            @empty
                <div class="rounded-2xl bg-slate-50 px-5 py-8 text-center">
                    <p class="font-semibold text-slate-700">No new announcements</p>
                    <p class="mt-1 text-sm text-slate-500">Published learner announcements will appear here.</p>
                </div>
            @endforelse
        </div>
    </section>

</div>
 

