<?php

use App\Models\EnrollmentApplication;
use App\Models\LearnerDocument;
use App\Models\LearnerProfile;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Enrollment Requirements');

$profile = computed(fn () => LearnerProfile::query()->where('user_id', auth()->id())->first());

$application = computed(fn () => EnrollmentApplication::query()
    ->where('learner_user_id', auth()->id())
    ->latest()
    ->first());

$documents = computed(fn () => LearnerDocument::query()
    ->where('user_id', auth()->id())
    ->latest()
    ->get());
?>

@php
    $learnerProfile = $this->profile;
    $application = $this->application;
    $documentMap = $this->documents->keyBy('document_type');
    $education = trim((string) ($learnerProfile?->educational_attainment ?? ''));
    $civilStatus = strtolower(trim((string) ($learnerProfile?->civil_status ?? '')));
    $requiresTor = in_array($education, ['High School Graduate', 'College Graduate'], true);
    $educationKnown = $education !== '';
    $requiresMarriageContract = $civilStatus === 'married';
    $civilStatusKnown = $civilStatus !== '';

    $statusClasses = fn (?string $status) => match ($status) {
        'verified' => 'bg-emerald-50 text-emerald-700',
        'rejected' => 'bg-rose-50 text-rose-700',
        'pending' => 'bg-amber-50 text-amber-800',
        default => 'bg-slate-100 text-slate-600',
    };
    $documentStatus = fn (string $type) => $documentMap->get($type)?->status;
@endphp

<div class="mx-auto max-w-5xl space-y-6 pb-10">
    <section class="overflow-hidden rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-900 p-6 text-white shadow-xl sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.25em] text-cyan-300">Student / Scholar</p>
        <h1 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">Enrollment Requirements</h1>
        <p class="mt-3 max-w-2xl text-sm leading-6 text-blue-100">Review the mandatory and conditional requirements before submitting your TESDA Learner Profile Form (MIS 03-01, version 2021).</p>
        <div class="mt-5 flex flex-wrap gap-3">
            <a href="{{ route('learner.registration') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-blue-900 hover:bg-blue-50">Submit Enrollment</a>
            <a href="https://www.tesda.gov.ph/Uploads/File/FORM/TESDA-DPA%20Form%201%20Registration%20Form%20%28MIS%2003-01%29.pdf" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center rounded-xl border border-white/30 bg-white/10 px-4 py-2.5 text-sm font-bold text-white hover:bg-white/20">Download blank TESDA form (PDF)</a>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div class="flex items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-700"><flux:icon icon="clipboard-document-list" class="size-5" /></span>
            <div>
                <h2 class="text-xl font-black text-slate-900">Required documents</h2>
                <p class="mt-1 text-sm text-slate-500">Accepted uploads: PDF, JPG, JPEG, or PNG. Maximum file size: 5 MB per document.</p>
            </div>
        </div>

        <div class="mt-5 divide-y divide-slate-100">
            <article class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="font-bold text-slate-900">1. TESDA Learner Profile / Registration Form</p>
                    <p class="mt-1 text-sm text-slate-600">MIS 03-01 (ver. 2021). Complete the digital form, including learner/manpower profile, personal information, learner/trainee classification, course/qualification, scholarship information, and privacy consent.</p>
                </div>
                <span class="inline-flex w-fit shrink-0 rounded-full px-3 py-1 text-xs font-bold {{ $learnerProfile?->submitted_at ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">{{ $learnerProfile?->submitted_at ? 'Submitted' : 'Mandatory' }}</span>
            </article>

            <article class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="font-bold text-slate-900">2. Birth Certificate</p>
                    <p class="mt-1 text-sm text-slate-600">Mandatory. The Registrar must verify the uploaded document before the enrollment can be verified.</p>
                    @if ($documentStatus('birth_certificate') === 'rejected' && filled($documentMap->get('birth_certificate')?->remarks))
                        <p class="mt-2 text-sm text-rose-700">Registrar remarks: {{ $documentMap->get('birth_certificate')->remarks }}</p>
                    @endif
                </div>
                @php($birthStatus = $documentStatus('birth_certificate'))
                <span class="inline-flex w-fit shrink-0 rounded-full px-3 py-1 text-xs font-bold {{ $birthStatus ? $statusClasses($birthStatus) : 'bg-amber-50 text-amber-800' }}">{{ $birthStatus ? str($birthStatus)->headline() : 'Required' }}</span>
            </article>

            <article class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="font-bold text-slate-900">3. Transcript of Records (TOR)</p>
                    @if (! $educationKnown)
                        <p class="mt-1 text-sm text-slate-600">Conditional. Select your educational attainment in the digital form so the system can determine whether this document is required.</p>
                    @elseif ($requiresTor)
                        <p class="mt-1 text-sm text-slate-600">Required for the selected educational attainment: {{ $education }}.</p>
                        @if ($documentStatus('tor') === 'rejected' && filled($documentMap->get('tor')?->remarks))
                            <p class="mt-2 text-sm text-rose-700">Registrar remarks: {{ $documentMap->get('tor')->remarks }}</p>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-slate-600">Not required based on your current educational attainment ({{ $education }}).</p>
                    @endif
                </div>
                @php($torStatus = $documentStatus('tor'))
                <span class="inline-flex w-fit shrink-0 rounded-full px-3 py-1 text-xs font-bold {{ ! $educationKnown ? 'bg-slate-100 text-slate-600' : (! $requiresTor ? 'bg-slate-100 text-slate-600' : ($torStatus ? $statusClasses($torStatus) : 'bg-amber-50 text-amber-800')) }}">{{ ! $educationKnown ? 'Conditional' : (! $requiresTor ? 'Not required' : ($torStatus ? str($torStatus)->headline() : 'Required')) }}</span>
            </article>

            <article class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="font-bold text-slate-900">4. Marriage Contract</p>
                    @if (! $civilStatusKnown)
                        <p class="mt-1 text-sm text-slate-600">Conditional. Choose your civil status in the digital form to determine whether this document is required.</p>
                    @elseif ($requiresMarriageContract)
                        <p class="mt-1 text-sm text-slate-600">Required because your recorded civil status is Married.</p>
                        @if ($documentStatus('marriage_contract') === 'rejected' && filled($documentMap->get('marriage_contract')?->remarks))
                            <p class="mt-2 text-sm text-rose-700">Registrar remarks: {{ $documentMap->get('marriage_contract')->remarks }}</p>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-slate-600">Not required based on your current civil status ({{ $learnerProfile->civil_status }}).</p>
                    @endif
                </div>
                @php($marriageStatus = $documentStatus('marriage_contract'))
                <span class="inline-flex w-fit shrink-0 rounded-full px-3 py-1 text-xs font-bold {{ ! $civilStatusKnown ? 'bg-slate-100 text-slate-600' : (! $requiresMarriageContract ? 'bg-slate-100 text-slate-600' : ($marriageStatus ? $statusClasses($marriageStatus) : 'bg-amber-50 text-amber-800')) }}">{{ ! $civilStatusKnown ? 'Conditional' : (! $requiresMarriageContract ? 'Not required' : ($marriageStatus ? str($marriageStatus)->headline() : 'Required')) }}</span>
            </article>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <h2 class="text-xl font-black text-slate-900">Digital registration form sections</h2>
        <p class="mt-1 text-sm text-slate-500">Use the online form to complete these sections. Do not enter another application if your enrollment has already been finalized.</p>
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            @foreach ([['Learner / Manpower Profile', 'Name, address and contact details'], ['Personal Information', 'Birth details, civil status, employment and education'], ['Learner / Trainee Classification', 'Classification and disability details when applicable'], ['Course / Qualification', 'Select your institution and offered course'], ['Scholarship Information', 'Scholarship package information'], ['Privacy Consent and Disclaimer', 'Review and agree before electronic submission']] as [$heading, $description])
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                    <p class="font-bold text-slate-800">{{ $heading }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $description }}</p>
                </div>
            @endforeach
        </div>
        @if ($application)
            <div class="mt-5 rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-sm font-semibold text-slate-700">Current enrollment status: <span class="font-black">{{ str($application->application_status)->headline() }}</span></p>
                @if (in_array($application->application_status, ['confirmed', 'verified'], true))
                    <p class="mt-1 text-sm text-slate-500">The current record has already moved forward in the review process. Contact the Registrar if a correction is required.</p>
                @endif
            </div>
        @endif
        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <a href="{{ route('learner.portal') }}" wire:navigate class="inline-flex rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Back to Dashboard</a>
            <a href="{{ route('learner.registration') }}" wire:navigate class="inline-flex rounded-xl bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800">Open Digital Registration Form</a>
        </div>
    </section>
</div>
