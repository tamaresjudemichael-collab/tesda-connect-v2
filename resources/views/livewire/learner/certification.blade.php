<?php

use App\Models\EnrollmentApplication;
use App\Models\TrainingCertificate;
use App\Support\TrainingCompletionService;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Certification & Completion');

$application = computed(fn () => EnrollmentApplication::query()
    ->with(['course', 'trainingBatch'])
    ->where('learner_user_id', auth()->id())
    ->latest()
    ->first());

$certificate = computed(fn () => $this->application
    ? TrainingCertificate::query()->where('enrollment_application_id', $this->application->id)->first()
    : null);

$eligibility = computed(fn () => $this->application
    ? app(TrainingCompletionService::class)->assess($this->application)
    : ['eligible' => false, 'pending_units' => ['No enrollment application found.']]);
?>

@php($record = $this->application)
@php($certificate = $this->certificate)
@php($eligibility = $this->eligibility)

<div class="mx-auto max-w-5xl space-y-6 pb-10">
    <section class="rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-900 p-6 text-white shadow-xl sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-300">Student / Scholar</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight">Certification &amp; Completion</h1>
        <p class="mt-2 text-sm text-blue-100">Check your training completion status and download your certificate once it is issued.</p>
    </section>

    @if (! $record)
        <section class="rounded-3xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm">
            <h2 class="text-xl font-black text-slate-900">No learner application yet</h2>
            <p class="mt-2 text-sm text-slate-500">Submit your registration first to begin your training record.</p>
        </section>
    @elseif ($record->application_status !== 'confirmed')
        <section class="rounded-3xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm">
            <h2 class="text-xl font-black text-slate-900">Certification is not available yet</h2>
            <p class="mt-2 text-sm text-slate-500">Your enrollment must be confirmed before completion can be assessed.</p>
        </section>
    @else
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-xl font-black text-slate-900">Training completion status</h2>
                    <p class="mt-1 text-sm text-slate-500">Based on verified competency units and training completion.</p>
                </div>
                @if ($certificate?->status === 'completed')
                    <span class="inline-flex w-fit rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700">READY</span>
                @elseif ($certificate?->status === 'not_completed')
                    <span class="inline-flex w-fit rounded-full bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-800">NOT COMPLETED</span>
                @elseif ($eligibility['eligible'])
                    <span class="inline-flex w-fit rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700">READY — AWAITING ISSUANCE</span>
                @else
                    <span class="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">NOT ELIGIBLE YET</span>
                @endif
            </div>

            @if ($certificate?->status === 'completed')
                <div class="mt-5 rounded-2xl border border-emerald-100 bg-emerald-50 p-5">
                    <p class="text-lg font-black text-emerald-900">Certificate of Training Completion</p>
                    <p class="mt-1 text-sm text-emerald-800">Certificate No.: {{ $certificate->certificate_number }}</p>
                    <a href="{{ route('certificates.print', $certificate) }}" target="_blank" class="mt-4 inline-flex rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">View / Print Certificate</a>
                </div>
            @elseif ($certificate?->status === 'not_completed')
                <p class="mt-5 text-sm text-slate-700">Your training is marked Not Completed. Check with your Focal Person about the remaining requirements.</p>
                @if (is_array($certificate->pending_units) && count($certificate->pending_units))
                    <ul class="mt-3 list-inside list-disc space-y-1 text-sm text-slate-600">
                        @foreach ($certificate->pending_units as $pendingUnit)<li>{{ $pendingUnit }}</li>@endforeach
                    </ul>
                @endif
            @elseif ($eligibility['eligible'])
                <p class="mt-5 text-sm text-blue-800">Your completion requirements appear complete. Your Focal Person can now review and issue your certificate.</p>
            @else
                <p class="mt-5 text-sm text-slate-600">Your certificate is not available yet. Your Focal Person must finalize your competency units and verify training completion.</p>
                @if (count($eligibility['pending_units']))
                    <ul class="mt-3 list-inside list-disc space-y-1 text-sm text-slate-600">
                        @foreach ($eligibility['pending_units'] as $pendingUnit)<li>{{ $pendingUnit }}</li>@endforeach
                    </ul>
                @endif
            @endif
        </section>
    @endif
</div>
