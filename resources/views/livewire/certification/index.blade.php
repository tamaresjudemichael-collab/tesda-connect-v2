<?php

use App\Models\EnrollmentApplication;
use App\Models\TrainingCertificate;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use App\Support\TrainingCompletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Certification Management');

state(['reasons' => []]);

$applications = computed(function () {
    $user = auth()->user();
    $institutionIds = InstitutionScope::idsFor($user);

    return EnrollmentApplication::query()
        ->with(['institution', 'course', 'trainingBatch'])
        ->where('application_status', 'confirmed')
        ->whereIn('institution_id', $institutionIds)
        ->latest('confirmed_at')
        ->limit(200)
        ->get()
        ->map(function (EnrollmentApplication $application) {
            $certificate = TrainingCertificate::query()
                ->where('enrollment_application_id', $application->id)
                ->first();
            $assessment = app(TrainingCompletionService::class)->assess($application);

            $application->setAttribute('phase2d_certificate', $certificate);
            $application->setAttribute('phase2d_assessment', $assessment);

            return $application;
        });
});

$findManagedApplication = function (int $applicationId): EnrollmentApplication {
    $user = auth()->user();
    abort_unless($user && $user->hasRole('administrator', 'focal_person'), 403);

    $application = EnrollmentApplication::query()
        ->with(['institution', 'course', 'trainingBatch'])
        ->findOrFail($applicationId);

    abort_unless($application->application_status === 'confirmed', 422, 'Only confirmed trainees can be certified.');

    if (! $user->hasRole('administrator')) {
        abort_unless(
            InstitutionScope::idsFor($user)->contains((int) $application->institution_id),
            403,
            'You may only manage certification for your assigned institution.'
        );
    }

    return $application;
};

$issueCertificate = function (int $applicationId): void {
    $application = $this->findManagedApplication($applicationId);
    $assessment = app(TrainingCompletionService::class)->assess($application);

    if (! $assessment['eligible']) {
        throw ValidationException::withMessages([
            'completion' => 'Certificate cannot be issued yet. Pending: '.implode('; ', $assessment['pending_units']),
        ]);
    }

    $certificate = DB::transaction(function () use ($applicationId): TrainingCertificate {
        $application = EnrollmentApplication::query()
            ->with(['institution', 'course', 'trainingBatch'])
            ->lockForUpdate()
            ->findOrFail($applicationId);

        $assessment = app(TrainingCompletionService::class)->assess($application);
        if (! $assessment['eligible']) {
            throw ValidationException::withMessages([
                'completion' => 'Completion requirements changed. Review the pending requirements before issuing.',
            ]);
        }

        $certificate = TrainingCertificate::query()
            ->where('enrollment_application_id', $application->id)
            ->first();

        if ($certificate?->status === 'completed') {
            return $certificate;
        }

        $certificate ??= new TrainingCertificate([
            'enrollment_application_id' => $application->id,
        ]);
        $certificate->fill([
            'certificate_number' => 'TC-'.now()->format('Y').'-'.Str::upper((string) Str::ulid()),
            'status' => 'completed',
            'pending_units' => [],
            'reason' => null,
            'issued_by_user_id' => auth()->id(),
            'issued_at' => now(),
        ]);
        $certificate->save();

        ActivityLogger::record(
            'training_certificate_issued',
            'Issued training completion certificate '.$certificate->certificate_number,
            (int) $application->institution_id,
            ['enrollment_application_id' => $application->id, 'certificate_id' => $certificate->id]
        );

        return $certificate;
    });

    session()->flash('status', 'Training completion certificate issued successfully.');
};

$markNotCompleted = function (int $applicationId): void {
    $application = $this->findManagedApplication($applicationId);
    $reasonKey = 'reasons.'.$applicationId;
    $this->validate([$reasonKey => ['required', 'string', 'max:2000']]);

    $existing = TrainingCertificate::query()
        ->where('enrollment_application_id', $application->id)
        ->first();
    abort_if($existing?->status === 'completed', 422, 'An issued certificate cannot be changed to Not Completed.');

    $assessment = app(TrainingCompletionService::class)->assess($application);
    if ($assessment['eligible']) {
        throw ValidationException::withMessages([
            $reasonKey => 'All configured completion requirements are satisfied. Issue the certificate instead.',
        ]);
    }

    $certificate = TrainingCertificate::query()->firstOrNew([
        'enrollment_application_id' => $application->id,
    ]);
    $certificate->fill([
        'certificate_number' => null,
        'status' => 'not_completed',
        'pending_units' => $assessment['pending_units'],
        'reason' => trim((string) $this->reasons[$applicationId]),
        'issued_by_user_id' => auth()->id(),
        'issued_at' => null,
    ]);
    $certificate->save();

    ActivityLogger::record(
        'training_not_completed',
        'Recorded trainee as Not Completed: '.$application->learner_name,
        (int) $application->institution_id,
        ['enrollment_application_id' => $application->id, 'certificate_id' => $certificate->id]
    );

    unset($this->reasons[$applicationId]);
    session()->flash('status', 'Not Completed status saved with pending requirements.');
};
?>

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <section class="rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-900 p-6 text-white shadow-xl sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-300">TESDA Connect</p>
        <h1 class="mt-3 text-3xl font-black">Certification Management</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-blue-100">Review training completion requirements and issue a completion certificate only when competency tasks are complete and units have been verified and locked. Grades and numerical scores are not used or displayed.</p>
    </section>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>
    @endif
    @error('completion')
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div>
    @enderror

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 p-5">
            <h2 class="text-lg font-black text-slate-900">Confirmed trainees</h2>
            <p class="mt-1 text-sm text-slate-500">Showing up to 200 confirmed enrollments within your authorized institution scope.</p>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse ($this->applications as $application)
                @php
                    $certificate = $application->phase2d_certificate;
                    $assessment = $application->phase2d_assessment;
                @endphp
                <article class="space-y-4 p-5" wire:key="certification-application-{{ $application->id }}">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <h3 class="text-base font-bold text-slate-900">{{ $application->learner_name }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ $application->scholar_id ?: 'No Scholar ID' }} · {{ $application->course?->name ?: 'No course assigned' }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $application->institution?->name }} · Batch: {{ $application->trainingBatch?->name ?: 'Not assigned' }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($certificate?->status === 'completed')
                                <span class="inline-flex rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700">COMPLETED</span>
                                <a href="{{ route('certificates.print', $certificate) }}" target="_blank" class="inline-flex rounded-lg bg-blue-700 px-3 py-2 text-xs font-bold text-white hover:bg-blue-800">Preview / Print Certificate</a>
                            @elseif ($certificate?->status === 'not_completed')
                                <span class="inline-flex rounded-full bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-800">NOT COMPLETED</span>
                            @elseif ($assessment['eligible'])
                                <span class="inline-flex rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700">ELIGIBLE</span>
                            @else
                                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">NOT YET ELIGIBLE</span>
                            @endif
                        </div>
                    </div>

                    @if ($certificate?->status === 'completed')
                        <div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">
                            Certificate No.: <span class="font-mono font-bold">{{ $certificate->certificate_number }}</span>
                            <span class="mx-2">·</span> Issued {{ $certificate->issued_at?->format('M d, Y') }}
                        </div>
                    @else
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                            <p class="text-sm font-bold text-slate-800">Pending requirements</p>
                            <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-slate-600">
                                @forelse ($assessment['pending_units'] as $pendingUnit)
                                    <li>{{ $pendingUnit }}</li>
                                @empty
                                    <li class="list-none text-emerald-700">All configured competency tasks and unit reviews are complete.</li>
                                @endforelse
                            </ul>
                        </div>

                        <div class="grid gap-3 lg:grid-cols-[1fr_auto_auto] lg:items-end">
                            <div>
                                <label for="reason-{{ $application->id }}" class="mb-1 block text-xs font-bold text-slate-700">Reason / pending units note (required to mark Not Completed)</label>
                                <textarea id="reason-{{ $application->id }}" wire:model="reasons.{{ $application->id }}" rows="2" maxlength="2000" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200" placeholder="Document the pending competency or reason..."></textarea>
                                @error('reasons.'.$application->id)<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                @if ($certificate?->status === 'not_completed' && filled($certificate->reason))
                                    <p class="mt-2 text-xs text-slate-600">Saved reason: {{ $certificate->reason }}</p>
                                @endif
                            </div>
                            <button type="button" wire:click="markNotCompleted({{ $application->id }})" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-900 hover:bg-amber-100">Mark Not Completed</button>
                            <button type="button" wire:click="issueCertificate({{ $application->id }})" @disabled(! $assessment['eligible']) class="rounded-xl bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-40">Issue Certificate</button>
                        </div>
                    @endif
                </article>
            @empty
                <div class="p-10 text-center text-sm text-slate-500">No confirmed trainees were found in your authorized institution scope.</div>
            @endforelse
        </div>
    </section>

    <p class="text-xs leading-5 text-slate-500">Certificate status is based on confirmed enrollment, an assigned training batch with start/end dates, configured Progress/Achievement tasks marked Competent (or Submitted Output Correct), and every relevant unit review marked Verified and Locked. This page does not use grades or numeric assessment scores.</p>
</div>
