<?php

use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\LearnerProfile;
use App\Models\LearnerDocument;
use App\Models\IntakePeriod;
use App\Models\IntakeCourseQuota;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, mount, state, title, usesFileUploads};
use Illuminate\Support\Facades\Storage;

layout('layouts::app');
title('Learner Registration Form');
usesFileUploads();

state([
    'current_step' => 1,
    'institution_id' => null,
    'course_id' => null,
    'uli_number' => '',
    'entry_date' => now()->toDateString(),
    'last_name' => '',
    'first_name' => '',
    'middle_name' => '',
    'extension_name' => '',
    'address_line' => '',
    'barangay' => '',
    'district' => '',
    'city_municipality' => '',
    'province' => '',
    'region' => '',
    'contact_number' => '',
    'nationality' => 'Filipino',
    'sex' => '',
    'civil_status' => '',
    'employment_status' => '',
    'employment_type' => '',
    'birthdate' => '',
    'birthplace_city' => '',
    'birthplace_province' => '',
    'birthplace_region' => '',
    'educational_attainment' => '',
    'guardian_name' => '',
    'guardian_address' => '',
    'classifications' => [],
    'classification_other' => '',
    'disability_types' => [],
    'disability_other' => '',
    'disability_cause' => '',
    'scholarship_package' => '',
    'privacy_consent' => false,
    'valid_id' => null,
    'profile_photo' => null,
    'thumbmark' => null,
    'birth_certificate' => null,
    'tor' => null,
    'marriage_contract' => null,
]);


$institutions = computed(function () {
    $user = auth()->user();

    if ($user->hasRole('applicant')) {
        return Institution::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    return $user->institutions()
        ->where('is_active', true)
        ->orderBy('name')
        ->get();
});

$courses = computed(fn () => Course::query()
    ->where('institution_id', $this->institution_id)
    ->where('is_active', true)
    ->orderBy('name')
    ->get());

$documentStatuses = computed(fn () => LearnerDocument::query()
    ->where('user_id', auth()->id())
    ->orderByDesc('id')
    ->get()
    ->unique('document_type')
    ->keyBy('document_type'));

mount(function (): void {
    $profile = auth()->user()->learnerProfile;

    if ($profile) {
        $this->fill($profile->only([
            'institution_id', 'course_id', 'uli_number', 'entry_date', 'last_name', 'first_name', 'middle_name', 'extension_name',
            'address_line', 'barangay', 'district', 'city_municipality', 'province', 'region',
            'contact_number', 'nationality', 'sex', 'civil_status', 'employment_status',
            'employment_type', 'birthplace_city', 'birthplace_province', 'birthplace_region',
            'educational_attainment', 'guardian_name', 'guardian_address', 'classifications',
            'disability_types', 'disability_cause', 'scholarship_package', 'privacy_consent',
        ]));
        $this->birthdate = $profile->birthdate?->toDateString() ?? '';
        $this->entry_date = $profile->entry_date?->toDateString() ?? now()->toDateString();

        $classificationOther = collect($this->classifications ?? [])->first(fn ($item) => str_starts_with($item, 'Others: '));
        if ($classificationOther) {
            $this->classifications = array_values(array_filter($this->classifications, fn ($item) => $item !== $classificationOther));
            $this->classifications[] = 'Others';
            $this->classification_other = substr($classificationOther, 8);
        }

        $disabilityOther = collect($this->disability_types ?? [])->first(fn ($item) => str_starts_with($item, 'Multiple Disabilities: '));
        if ($disabilityOther) {
            $this->disability_types = array_values(array_filter($this->disability_types, fn ($item) => $item !== $disabilityOther));
            $this->disability_types[] = 'Multiple Disabilities';
            $this->disability_other = substr($disabilityOther, 23);
        }

        return;
    }

    // Prefer the institution selected when this account was registered.
    // Keep the first available institution only as a fallback for legacy accounts
    // that do not yet have a primary institution assignment.
    $primaryInstitutionId = auth()->user()
        ->institutions()
        ->wherePivot('is_primary', true)
        ->value('institutions.id');

    $this->institution_id = $primaryInstitutionId
        ?? $this->institutions->first()?->id;

    [$firstName, $lastName] = array_pad(explode(' ', auth()->user()->name, 2), 2, '');
    $this->first_name = $firstName;
    $this->last_name = $lastName;
});

$updatedInstitutionId = function (): void {
    $this->course_id = null;
    unset($this->courses);
};

$nextStep = function (): void {
    $needsUpload = function (string $documentType): bool {
        $latestDocument = LearnerDocument::query()
            ->where('user_id', auth()->id())
            ->where('document_type', $documentType)
            ->latest('id')
            ->first();

        return ! $latestDocument || $latestDocument->status === 'rejected';
    };

    $rules = match ((int) $this->current_step) {
        1 => [
            'uli_number' => ['nullable', 'string', 'max:40'],
            'entry_date' => ['required', 'date'],
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'extension_name' => ['nullable', 'string', 'max:20'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'city_municipality' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'region' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:30'],
            'nationality' => ['required', 'string', 'max:100'],
        ],
        2 => [
            'sex' => ['required', Rule::in(['male', 'female'])],
            'civil_status' => ['required', 'string', 'max:30'],
            'employment_status' => ['nullable', 'string', 'max:30'],
            'employment_type' => ['nullable', 'string', 'max:30'],
            'birthdate' => ['required', 'date', 'before:today'],
            'birthplace_city' => ['nullable', 'string', 'max:100'],
            'birthplace_province' => ['nullable', 'string', 'max:100'],
            'birthplace_region' => ['nullable', 'string', 'max:100'],
            'educational_attainment' => ['required', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'guardian_address' => ['nullable', 'string', 'max:255'],
        ],
        3 => [
            'classifications' => ['nullable', 'array'],
            'classifications.*' => ['string', 'max:150'],
            'classification_other' => [Rule::requiredIf(in_array('Others', $this->classifications ?? [], true)), 'nullable', 'string', 'max:150'],
        ],
        4 => [
            'disability_types' => ['nullable', 'array'],
            'disability_types.*' => ['string', 'max:150'],
            'disability_other' => [Rule::requiredIf(in_array('Multiple Disabilities', $this->disability_types ?? [], true)), 'nullable', 'string', 'max:150'],
            'disability_cause' => ['nullable', 'string', 'max:30'],
        ],
        5 => [
            'institution_id' => ['required', Rule::exists('institutions', 'id')->where('is_active', true)],
            'course_id' => [
                'required',
                Rule::exists('courses', 'id')->where(fn ($query) => $query
                    ->where('institution_id', $this->institution_id)
                    ->where('is_active', true)),
            ],
            'scholarship_package' => ['nullable', 'string', 'max:100'],
        ],
        6 => [
            'valid_id' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'profile_photo' => ['nullable', 'image', 'max:5120'],
            'thumbmark' => ['nullable', 'image', 'max:5120'],
            'birth_certificate' => [$needsUpload('birth_certificate') ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'tor' => [in_array($this->educational_attainment, ['High School Graduate', 'College Graduate'], true) && $needsUpload('tor') ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'marriage_contract' => [$this->civil_status === 'Married' && $needsUpload('marriage_contract') ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ],
        default => [],
    };

    $this->validate($rules);
    $this->resetValidation();
    $this->current_step = min(7, (int) $this->current_step + 1);
};

$previousStep = function (): void {
    $this->resetValidation();
    $this->current_step = max(1, (int) $this->current_step - 1);
};

$save = function (): void {
    $user = auth()->user();

    $existingApplication = EnrollmentApplication::query()
        ->where('learner_user_id', $user->id)
        ->first();

    if ($existingApplication && ! in_array($existingApplication->application_status, ['draft', 'returned_for_revision'], true)) {
        Flux::toast(
            variant: 'warning',
            text: 'This enrollment is already submitted or finalized. Contact the Registrar if a correction is needed.'
        );

        return;
    }

    $needsUpload = function (string $documentType) use ($user): bool {
        $latestDocument = LearnerDocument::query()
            ->where('user_id', $user->id)
            ->where('document_type', $documentType)
            ->latest('id')
            ->first();

        return ! $latestDocument || $latestDocument->status === 'rejected';
    };

    $requiresTor = in_array($this->educational_attainment, ['High School Graduate', 'College Graduate'], true);
    $requiresMarriageContract = $this->civil_status === 'Married';

    $validated = $this->validate([
        'uli_number' => ['nullable', 'string', 'max:40'],
        'entry_date' => ['required', 'date'],
        'institution_id' => ['required', Rule::exists('institutions', 'id')->where('is_active', true)],
        'course_id' => [
            'required',
            Rule::exists('courses', 'id')->where(fn ($query) => $query
                ->where('institution_id', $this->institution_id)
                ->where('is_active', true)),
        ],
        'last_name' => ['required', 'string', 'max:100'],
        'first_name' => ['required', 'string', 'max:100'],
        'middle_name' => ['nullable', 'string', 'max:100'],
        'extension_name' => ['nullable', 'string', 'max:20'],
        'address_line' => ['nullable', 'string', 'max:255'],
        'barangay' => ['nullable', 'string', 'max:100'],
        'district' => ['nullable', 'string', 'max:100'],
        'city_municipality' => ['required', 'string', 'max:100'],
        'province' => ['required', 'string', 'max:100'],
        'region' => ['required', 'string', 'max:100'],
        'contact_number' => ['required', 'string', 'max:30'],
        'nationality' => ['required', 'string', 'max:100'],
        'sex' => ['required', Rule::in(['male', 'female'])],
        'civil_status' => ['required', 'string', 'max:30'],
        'employment_status' => ['nullable', 'string', 'max:30'],
        'employment_type' => ['nullable', 'string', 'max:30'],
        'birthdate' => ['required', 'date', 'before:today'],
        'birthplace_city' => ['nullable', 'string', 'max:100'],
        'birthplace_province' => ['nullable', 'string', 'max:100'],
        'birthplace_region' => ['nullable', 'string', 'max:100'],
        'educational_attainment' => ['required', 'string', 'max:255'],
        'guardian_name' => ['nullable', 'string', 'max:150'],
        'guardian_address' => ['nullable', 'string', 'max:255'],
        'classifications' => ['nullable', 'array'],
        'classifications.*' => ['string', 'max:150'],
        'classification_other' => [Rule::requiredIf(in_array('Others', $this->classifications ?? [], true)), 'nullable', 'string', 'max:150'],
        'disability_types' => ['nullable', 'array'],
        'disability_types.*' => ['string', 'max:150'],
        'disability_other' => [Rule::requiredIf(in_array('Multiple Disabilities', $this->disability_types ?? [], true)), 'nullable', 'string', 'max:150'],
        'disability_cause' => ['nullable', 'string', 'max:30'],
        'scholarship_package' => ['nullable', 'string', 'max:100'],
        'privacy_consent' => ['accepted'],
        'valid_id' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        'profile_photo' => ['nullable', 'image', 'max:5120'],
        'thumbmark' => ['nullable', 'image', 'max:5120'],
        'birth_certificate' => [$needsUpload('birth_certificate') ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        'tor' => [$requiresTor && $needsUpload('tor') ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        'marriage_contract' => [$requiresMarriageContract && $needsUpload('marriage_contract') ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
    ]);
    $classifications = $validated['classifications'] ?? [];
 
    if (in_array('Others', $classifications, true)) {
        $classifications = array_values(array_filter($classifications, fn ($item) => $item !== 'Others'));
        $classifications[] = 'Others: '.$validated['classification_other'];
    }

    $disabilityTypes = $validated['disability_types'] ?? [];
    if (in_array('Multiple Disabilities', $disabilityTypes, true)) {
        $disabilityTypes = array_values(array_filter($disabilityTypes, fn ($item) => $item !== 'Multiple Disabilities'));
        $disabilityTypes[] = 'Multiple Disabilities: '.$validated['disability_other'];
    }

    $validated['classifications'] = $classifications ?: null;
    $validated['disability_types'] = $disabilityTypes ?: null;
    unset($validated['classification_other'], $validated['disability_other']);
    $profileData = $validated;
    unset($profileData['valid_id'], $profileData['profile_photo'], $profileData['birth_certificate'], $profileData['tor'], $profileData['marriage_contract']);


$institutionQuery = Institution::query()
    ->whereKey($validated['institution_id'])
    ->where('is_active', true);

if (! $user->hasRole('applicant')) {
    $institutionQuery->whereHas('users', function ($query) use ($user) {
        $query->where('users.id', $user->id);
    });
}

abort_unless($institutionQuery->exists(), 403);
$oldDocumentPaths = [];
$periodDenied = false;
$submissionBlocked = false;
DB::transaction(function () use ($validated, $profileData, $user, &$oldDocumentPaths, &$periodDenied, &$submissionBlocked): void {
        $existingApplication = EnrollmentApplication::query()
            ->where('learner_user_id', $user->id)
            ->lockForUpdate()
            ->first();

        // Re-check under the row lock to protect against a concurrent submit/finalize.
        // Return a state to Livewire rather than aborting with a full-page 403 response.
        if ($existingApplication && ! in_array($existingApplication->application_status, ['draft', 'returned_for_revision'], true)) {
            $submissionBlocked = true;
            return;
        }

        $isRevisionOfExistingSubmission = $existingApplication
            && $existingApplication->application_status === 'returned_for_revision';
        $hasConfiguredIntakePeriods = IntakePeriod::query()->where(fn ($query) => $query->whereNull('institution_id')->orWhere('institution_id', (int) $validated['institution_id']))->exists();
        $activeIntake = IntakePeriod::query()
            ->where(fn ($query) => $query->whereNull('institution_id')->orWhere('institution_id', (int) $validated['institution_id']))
            ->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today())
            ->orderByRaw('CASE WHEN institution_id = ? THEN 0 ELSE 1 END', [(int) $validated['institution_id']])
            ->orderBy('starts_at')
            ->lockForUpdate()
            ->first();

        if ($hasConfiguredIntakePeriods && ! $activeIntake && ! $isRevisionOfExistingSubmission) {
            $periodDenied = true;
            return;
        }

        if ($activeIntake) {
            $intakeActual = EnrollmentApplication::query()
                ->whereNull('archived_at')
                ->whereNotNull('submitted_at')
                ->where('submitted_at', '>=', $activeIntake->starts_at->copy()->startOfDay())
                ->where('submitted_at', '<=', $activeIntake->ends_at->copy()->endOfDay())
                ->when($activeIntake->institution_id, fn ($query) => $query->where('institution_id', $activeIntake->institution_id))
                ->count();
            if (($activeIntake->is_locked || $intakeActual >= $activeIntake->quota) && ! $isRevisionOfExistingSubmission) {
                if (! $activeIntake->is_locked && $intakeActual >= $activeIntake->quota) {
                    $activeIntake->forceFill([
                        'is_locked' => true,
                        'locked_at' => now(),
                        'locked_by_user_id' => null,
                    ])->save();
                    \App\Support\ActivityLogger::record(
                        'intake_period_auto_locked',
                        'Automatically locked intake period '.$activeIntake->name.' because its quota was reached.',
                        null,
                        ['intake_period_id' => $activeIntake->id, 'quota' => $activeIntake->quota]
                    );
                }

                $periodDenied = true;
                return;
            }
        }

        if ($activeIntake && ! $isRevisionOfExistingSubmission) {
            $courseQuota = IntakeCourseQuota::query()
                ->where('intake_period_id', $activeIntake->id)
                ->where('course_id', (int) $validated['course_id'])
                ->first();

            if ($courseQuota) {
                $courseActual = EnrollmentApplication::query()
                    ->whereNull('archived_at')
                    ->whereNotNull('submitted_at')
                    ->where('course_id', (int) $validated['course_id'])
                    ->where('submitted_at', '>=', $activeIntake->starts_at->copy()->startOfDay())
                    ->where('submitted_at', '<=', $activeIntake->ends_at->copy()->endOfDay())
                    ->when($activeIntake->institution_id, fn ($query) => $query->where('institution_id', $activeIntake->institution_id))
                    ->count();

                if ($courseActual >= $courseQuota->quota) {
                    $periodDenied = true;
                    return;
                }
            }
        }

        $profile = LearnerProfile::updateOrCreate(
            ['user_id' => $user->id],
            [...$profileData, 'submitted_at' => now()],
        );

$referenceNo = $existingApplication?->reference_no;
$previousApplicationStatus = $existingApplication?->application_status;

if (! $referenceNo) {
    do {
        $referenceNo = 'TC-'.now()->format('Y').'-'.strtoupper(
            \Illuminate\Support\Str::random(8)
        );
    } while (
        EnrollmentApplication::where('reference_no', $referenceNo)->exists()
    );
}
    $application = EnrollmentApplication::updateOrCreate(
        ['learner_user_id' => $user->id],
        [
            'reference_no' => $referenceNo,
            'institution_id' => $profile->institution_id,
            'course_id' => $profile->course_id,
            'learner_name' => trim(implode(' ', array_filter([
                $profile->first_name,
                $profile->middle_name,
                $profile->last_name,
                $profile->extension_name,
            ]))),
            'email' => $user->email,
            'contact_number' => $profile->contact_number,
            'application_status' => 'submitted',
            'verified_at' => null,
            'confirmed_at' => null,
            'scholar_id' => null,
            'record_completeness' => 0,
            'submitted_at' => now(),
        ],
    );

    if ($previousApplicationStatus !== 'submitted') {
        \App\Support\EnrollmentStatusRecorder::record(
            $application,
            $previousApplicationStatus,
            'submitted',
            $previousApplicationStatus === 'returned_for_revision'
                ? 'Learner resubmitted the requested revisions.'
                : 'Learner submitted the registration form.'
        );
    }

        foreach ([
            'valid_id' => 'valid_id',
            'profile_photo' => 'profile_photo',
            'thumbmark' => 'thumbmark',
            'birth_certificate' => 'birth_certificate',
            'tor' => 'tor',
            'marriage_contract' => 'marriage_contract',
        ] as $property => $type) {
$file = $validated[$property] ?? null;

if (! $file) {
    continue;
}

    
    $path = $file->store("learner-documents/{$user->id}", 'local');

    if (! $path) {
        throw new \RuntimeException('Unable to store learner document.');
    }

    $existingDocuments = LearnerDocument::query()
        ->where('user_id', $user->id)
        ->where('document_type', $type)
        ->orderByDesc('id')
        ->get();

    $document = $existingDocuments->first();

    foreach ($existingDocuments as $existingDocument) {
        if ($existingDocument->path !== $path) {
            $oldDocumentPaths[] = $existingDocument->path;
        }

        if ($document && $existingDocument->id !== $document->id) {
            $existingDocument->delete();
        }
    }

    $documentData = [
        'learner_profile_id' => $profile->id,
        'document_type' => $type,
        'original_name' => $file->getClientOriginalName(),
        'path' => $path,
        'mime_type' => $file->getMimeType(),
        'size' => $file->getSize(),
        'status' => 'pending',
        'reviewed_by_user_id' => null,
        'reviewed_at' => null,
        'remarks' => null,
    ];

    if ($document) {
        $document->update($documentData);
    } else {
        LearnerDocument::create([
            'user_id' => $user->id,
            ...$documentData,
        ]);
    }
}

        $assessment = app(\App\Support\EnrollmentVerificationService::class)->assess($application->fresh());
        $application->update(['record_completeness' => $assessment['percentage']]);

        if ($activeIntake) {
            $intakeActual = EnrollmentApplication::query()
                ->whereNull('archived_at')
                ->whereNotNull('submitted_at')
                ->where('submitted_at', '>=', $activeIntake->starts_at->copy()->startOfDay())
                ->where('submitted_at', '<=', $activeIntake->ends_at->copy()->endOfDay())
                ->when($activeIntake->institution_id, fn ($query) => $query->where('institution_id', $activeIntake->institution_id))
                ->count();
            if ($intakeActual >= $activeIntake->quota && ! $activeIntake->is_locked) {
                $activeIntake->forceFill([
                    'is_locked' => true,
                    'locked_at' => now(),
                    'locked_by_user_id' => null,
                ])->save();
                \App\Support\ActivityLogger::record(
                    'intake_period_auto_locked',
                    'Automatically locked intake period '.$activeIntake->name.' after the submission reached its quota.',
                    null,
                    ['intake_period_id' => $activeIntake->id, 'quota' => $activeIntake->quota]
                );
            }
        }
    });

if ($submissionBlocked) {
    Flux::toast(
        variant: 'warning',
        text: 'This enrollment is already submitted or finalized. Contact the Registrar if a correction is needed.'
    );

    return;
}

if ($periodDenied) {
    Flux::toast(variant: 'danger', text: 'The current intake period is closed, the overall quota is full, or the selected course quota has been reached. Please contact TESDA staff for guidance.');
    return;
}

if ($oldDocumentPaths !== []) {
    Storage::disk('local')->delete(array_values(array_unique($oldDocumentPaths)));
}
    Flux::toast(variant: 'success', text: 'Your learner registration was submitted for staff review.');
    $this->redirectRoute('learner.portal', navigate: true);
};
?>

<div class="learner-registration-page mx-auto max-w-5xl space-y-6">
    <div>
        <p class="text-sm font-semibold text-cyan-600">TESDA MIS 03-01 · Learners Profile Form</p>
        <flux:heading size="xl" class="mt-1 text-3xl font-black">Learner Registration Form</flux:heading>
        <flux:text class="mt-1">Complete each section of the TESDA Learners Profile Form. Your information will be submitted electronically for staff review.</flux:text>
    </div>

    @php
        $steps = [
            1 => 'Learner Profile', 2 => 'Personal Info', 3 => 'Classification',
            4 => 'Disability', 5 => 'Course & Scholarship', 6 => 'Documents', 7 => 'Review',
        ];
    @endphp

    <form wire:submit="save" class="space-y-6">
        <flux:card class="bg-white p-4 shadow-sm sm:p-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-bold text-cyan-700">Step {{ $current_step }} of 7</p>
                    <p class="mt-1 text-sm text-slate-500">Complete this section before proceeding.</p>
                </div>
                <span class="rounded-full bg-blue-50 px-3 py-1.5 text-sm font-bold text-blue-700">{{ $steps[$current_step] }}</span>
            </div>
            <div class="mt-5 grid grid-cols-7 gap-1.5 sm:gap-3">
                @foreach ($steps as $number => $label)
                    <div class="min-w-0">
                        <div class="h-2 rounded-full {{ $number <= $current_step ? 'bg-cyan-500' : 'bg-slate-200' }}"></div>
                        <p class="mt-2 truncate text-center text-[9px] font-semibold {{ $number === $current_step ? 'text-blue-700' : 'text-slate-400' }} sm:text-xs">{{ $number }}. {{ $label }}</p>
                    </div>
                @endforeach
            </div>
        </flux:card>

        @if ($current_step === 1)
            <flux:card class="bg-white p-5 shadow-sm sm:p-6">
                <div class="mb-6">
                    <flux:heading size="lg">1. Learner / Manpower Profile</flux:heading>
                    <flux:text class="mt-1">T2MIS learner identification and complete personal/contact details.</flux:text>
                </div>
                <div class="rounded-xl border border-cyan-100 bg-cyan-50/60 p-4">
                    <p class="text-sm font-bold text-cyan-900">T2MIS Learner Information</p>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="uli_number" label="Unique Learner Identifier (ULI) Number" placeholder="Encoder: enter the ULI number" />
                        <flux:input wire:model="entry_date" type="date" label="Entry Date" required />
                    </div>
                </div>
                <div class="mt-6">
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-700">Complete Name</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <flux:input wire:model="last_name" label="Last Name" required />
                        <flux:input wire:model="first_name" label="First Name" required />
                        <flux:input wire:model="middle_name" label="Middle Name" />
                        <flux:input wire:model="extension_name" label="Extension (Jr., Sr.)" />
                    </div>
                </div>
                <div class="mt-6">
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-700">Complete Permanent Mailing Address</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <flux:input wire:model="address_line" label="Number / Street" />
                        <flux:input wire:model="barangay" label="Barangay" />
                        <flux:input wire:model="district" label="District" />
                        <flux:input wire:model="city_municipality" label="City / Municipality" required />
                        <flux:input wire:model="province" label="Province" required />
                        <flux:input wire:model="region" label="Region" required />
                    </div>
                </div>
                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    <flux:input wire:model="contact_number" label="Contact Number" required />
                    <flux:input :value="auth()->user()->email" label="Email Address" type="email" disabled />
                    <flux:input wire:model="nationality" label="Nationality" required />
                </div>
            </flux:card>
        @elseif ($current_step === 2)
            <flux:card class="bg-white p-5 shadow-sm sm:p-6">
                <div class="mb-6"><flux:heading size="lg">2. Personal Information</flux:heading><flux:text class="mt-1">Personal details, employment, education and parent/guardian information.</flux:text></div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <flux:select wire:model="sex" label="Sex" required><flux:select.option value="">Select</flux:select.option><flux:select.option value="male">Male</flux:select.option><flux:select.option value="female">Female</flux:select.option></flux:select>
                    <flux:select wire:model="civil_status" label="Civil Status" required><flux:select.option value="">Select</flux:select.option>@foreach(['Single','Married','Separated / Divorced / Annulled','Widow / Widower','Common Law / Live-in'] as $option)<flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>@endforeach</flux:select>
                    <flux:input wire:model="birthdate" type="date" label="Birthdate" required />
                    <flux:input label="Age" :value="$birthdate ? \Carbon\Carbon::parse($birthdate)->age : ''" disabled />
                    <flux:select wire:model="employment_status" label="Employment Status"><flux:select.option value="">Select</flux:select.option>@foreach(['Wage-Employed','Underemployed','Self-Employed','Unemployed'] as $option)<flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>@endforeach</flux:select>
                    <flux:select wire:model="employment_type" label="Employment Type"><flux:select.option value="">Select</flux:select.option>@foreach(['None','Casual','Job Order','Probationary','Contractual','Regular','Permanent','Temporary'] as $option)<flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>@endforeach</flux:select>
                </div>
                <div class="mt-6"><h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-700">Birthplace</h3><div class="grid gap-4 sm:grid-cols-3"><flux:input wire:model="birthplace_city" label="City / Municipality" /><flux:input wire:model="birthplace_province" label="Province" /><flux:input wire:model="birthplace_region" label="Region" /></div></div>
                <div class="mt-6"><flux:select wire:model="educational_attainment" label="Educational Attainment Before the Training" required><flux:select.option value="">Select</flux:select.option>@foreach(['No Grade Completed','Elementary Undergraduate','Elementary Graduate','High School Undergraduate','High School Graduate','Junior High (K-12)','Senior High (K-12)','Post-Secondary Non-Tertiary / TVET Course Undergraduate','Post-Secondary Non-Tertiary / TVET Course Graduate','College Undergraduate','College Graduate','Masteral','Doctorate'] as $option)<flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>@endforeach</flux:select></div>
                <div class="mt-6"><h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-700">Parent / Guardian</h3><div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="guardian_name" label="Name" /><flux:input wire:model="guardian_address" label="Complete Permanent Mailing Address" /></div></div>
            </flux:card>
        @elseif ($current_step === 3)
            <flux:card class="bg-white p-5 shadow-sm sm:p-6">
                <div class="mb-6"><flux:heading size="lg">3. Learner / Trainee / Student (Clients) Classification</flux:heading><flux:text class="mt-1">Select all classifications that apply. You may select more than one.</flux:text></div>
                <div class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach(['4Ps Beneficiary', 'Agrarian Reform Beneficiary', 'Balik Probinsya', 'Displaced Workers', 'Drug Dependents / Surrenderees / Surrenderers', 'Family Members of AFP and PNP Killed-in-Action', 'Family Members of AFP and PNP Wounded-in-Action', 'Farmers and Fishermen', 'Indigenous People & Cultural Communities', 'Industry Workers', 'Inmates and Detainees', 'MILF Beneficiary', 'Out-of-School-Youth', 'Overseas Filipino Workers (OFW) Dependent', 'RCEF-RESP', 'Rebel Returnees / Decommissioned Combatants', 'Returning / Repatriated Overseas Filipino Workers (OFW)', 'Student', 'TESDA Alumni', 'TVET Trainers', 'Uniformed Personnel', 'Victim of Natural Disasters and Calamities', 'Wounded-in-Action AFP & PNP Personnel', 'Others'] as $classification)
                        <flux:checkbox wire:model="classifications" value="{{ $classification }}" label="{{ $classification }}" />
                    @endforeach
                </div>
                @if(in_array('Others', $classifications ?? [], true))<div class="mt-5"><flux:input wire:model="classification_other" label="Others — Please Specify" required /></div>@endif
            </flux:card>
        @elseif ($current_step === 4)
            <flux:card class="bg-white p-5 shadow-sm sm:p-6">
                <div class="mb-6"><flux:heading size="lg">4. Disability Information</flux:heading><flux:text class="mt-1">To be filled up for persons with disability only. Leave blank if not applicable.</flux:text></div>
                <h3 class="font-semibold text-slate-900">Type of Disability</h3>
                <div class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">@foreach(['Mental / Intellectual','Visual Disability','Orthopedic (Musculoskeletal) Disability','Hearing Disability','Speech Impairment','Multiple Disabilities','Psychosocial Disability','Disability Due to Chronic Illness','Learning Disability'] as $type)<flux:checkbox wire:model="disability_types" value="{{ $type }}" label="{{ $type }}" />@endforeach</div>
                @if(in_array('Multiple Disabilities', $disability_types ?? [], true))<div class="mt-5"><flux:input wire:model="disability_other" label="Multiple Disabilities — Please Specify" required /></div>@endif
                <div class="mt-8 border-t border-slate-100 pt-6"><h3 class="font-semibold text-slate-900">Cause of Disability</h3><flux:text class="mt-1">For persons with disability only.</flux:text><div class="mt-4"><flux:select wire:model="disability_cause" label="Cause of Disability"><flux:select.option value="">Select if applicable</flux:select.option><flux:select.option value="Congenital / Inborn">Congenital / Inborn</flux:select.option><flux:select.option value="Illness">Illness</flux:select.option><flux:select.option value="Injury">Injury</flux:select.option></flux:select></div></div>
            </flux:card>
        @elseif ($current_step === 5)
            <flux:card class="bg-white p-5 shadow-sm sm:p-6">
                <div class="mb-6"><flux:heading size="lg">5. Name of Course / Qualification</flux:heading><flux:text class="mt-1">Select the institution and qualification for this learner registration.</flux:text></div>
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:select wire:model.live="institution_id" label="Institution" required><flux:select.option value="">Select an institution</flux:select.option>@foreach($this->institutions as $institution)<flux:select.option value="{{ $institution->id }}">{{ $institution->name }}</flux:select.option>@endforeach</flux:select>
                    <flux:select wire:model="course_id" label="Course / Qualification" required :disabled="! $institution_id"><flux:select.option value="">Select a course</flux:select.option>@foreach($this->courses as $course)<flux:select.option value="{{ $course->id }}">{{ $course->name }}{{ $course->qualification_level ? ' · '.$course->qualification_level : '' }}</flux:select.option>@endforeach</flux:select>
                </div>
                <div class="mt-6"><flux:heading size="lg">6. Scholarship Package</flux:heading><flux:text class="mt-1">If scholar, indicate the type of scholarship package.</flux:text><flux:select wire:model="scholarship_package" class="mt-4" label="Scholarship Package"><flux:select.option value="">Select if applicable</flux:select.option>@foreach(['TWSP','PESFA','STEP','Others'] as $package)<flux:select.option value="{{ $package }}">{{ $package }}</flux:select.option>@endforeach</flux:select></div>
            </flux:card>
        @elseif ($current_step === 6)
            <flux:card class="bg-white p-5 shadow-sm sm:p-6">
                <div class="mb-6"><flux:heading size="lg">Supporting Documents</flux:heading><flux:text class="mt-1">Upload the required documents. Existing document review status and requirements are retained.</flux:text></div>
                @if ($this->documentStatuses->isNotEmpty())<div class="mb-5 rounded-xl border border-slate-200 bg-slate-50 p-4"><h3 class="font-semibold text-slate-900">Your latest document review</h3><div class="mt-3 space-y-3">@foreach ($this->documentStatuses as $type => $document)<div wire:key="learner-document-status-{{ $document->id }}" class="rounded-lg border border-slate-200 bg-white p-3"><div class="flex flex-wrap items-center justify-between gap-2"><span class="font-medium text-slate-800">{{ str($type)->headline() }}</span><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $document->status === 'verified' ? 'bg-emerald-100 text-emerald-800' : ($document->status === 'rejected' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800') }}">{{ $document->status === 'rejected' ? 'Revision required' : str($document->status)->headline() }}</span></div>@if ($document->status === 'rejected')<p class="mt-2 text-sm text-rose-800"><strong>Staff remarks:</strong> {{ $document->remarks ?: 'Please upload a corrected copy.' }}</p>@endif</div>@endforeach</div></div>@endif
                <div class="grid gap-5 md:grid-cols-2"><flux:input wire:model="birth_certificate" type="file" label="Birth Certificate (required)" accept=".jpg,.jpeg,.png,.pdf" />@if (in_array($educational_attainment, ['High School Graduate', 'College Graduate'], true))<flux:input wire:model="tor" type="file" label="Transcript of Records (TOR) (required)" accept=".jpg,.jpeg,.png,.pdf" />@endif@if ($civil_status === 'Married')<flux:input wire:model="marriage_contract" type="file" label="Marriage Contract (required)" accept=".jpg,.jpeg,.png,.pdf" />@endif<flux:input wire:model="valid_id" type="file" label="Valid ID (optional)" accept=".jpg,.jpeg,.png,.pdf" /><flux:input wire:model="profile_photo" type="file" label="1x1 Profile Photo (optional)" accept="image/jpeg,image/png" /><flux:input wire:model="thumbmark" type="file" label="Right Thumbmark (optional)" accept="image/jpeg,image/png" /></div>
                <p class="mt-3 text-xs text-slate-400">Allowed: JPG, PNG, or PDF · Maximum 5 MB per file.</p>
            </flux:card>
        @else
            <flux:card class="border-emerald-100 bg-emerald-50 p-5 shadow-sm sm:p-6">
                <flux:heading size="lg">7. Privacy Consent, Disclaimer &amp; Review</flux:heading>
                <p class="mt-3 text-sm leading-6 text-emerald-900/75">I hereby attest that the information stated in this Learners Profile is true and correct. I have read and understood the TESDA Privacy Notice and consent to the processing of my personal information for training-related programs.</p>
                <dl class="mt-5 grid gap-4 rounded-xl border border-emerald-100 bg-white p-5 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">ULI Number</dt><dd class="mt-1 font-bold text-slate-900">{{ $uli_number ?: 'Not yet issued' }}</dd></div>
                    <div><dt class="text-slate-500">Entry Date</dt><dd class="mt-1 font-bold text-slate-900">{{ $entry_date ?: 'Not completed' }}</dd></div>
                    <div><dt class="text-slate-500">Learner</dt><dd class="mt-1 font-bold text-slate-900">{{ trim($first_name.' '.$middle_name.' '.$last_name.' '.$extension_name) ?: 'Not completed' }}</dd></div>
                    <div><dt class="text-slate-500">Contact Number</dt><dd class="mt-1 font-bold text-slate-900">{{ $contact_number ?: 'Not completed' }}</dd></div>
                    <div><dt class="text-slate-500">Institution</dt><dd class="mt-1 font-bold text-slate-900">{{ $this->institutions->firstWhere('id', $institution_id)?->name ?? 'Not selected' }}</dd></div>
                    <div><dt class="text-slate-500">Course / Qualification</dt><dd class="mt-1 font-bold text-slate-900">{{ $this->courses->firstWhere('id', $course_id)?->name ?? 'Not selected' }}</dd></div>
                    <div><dt class="text-slate-500">Scholarship Package</dt><dd class="mt-1 font-bold text-slate-900">{{ $scholarship_package ?: 'Not specified' }}</dd></div>
                    <div><dt class="text-slate-500">Classification</dt><dd class="mt-1 font-bold text-slate-900">{{ count($classifications ?? []) ? implode(', ', $classifications) : 'None selected' }}</dd></div>
                </dl>
                <div class="mt-6 rounded-xl border border-emerald-200 bg-white p-4"><flux:checkbox wire:model="privacy_consent" label="Agree — I have read the TESDA Privacy Notice and consent to the processing of my personal information." required /></div>
            </flux:card>
        @endif

        <div class="sticky bottom-3 z-10 flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur">
            @if ($current_step > 1)<flux:button type="button" wire:click="previousStep" icon="arrow-left">Previous</flux:button>@else<div></div>@endif
            @if ($current_step < 7)<flux:button type="button" wire:click="nextStep" variant="primary" icon:trailing="arrow-right">Next</flux:button>@else<flux:button type="submit" variant="primary" icon="paper-airplane">Submit Learner Registration</flux:button>@endif
        </div>
    </form>
</div>
