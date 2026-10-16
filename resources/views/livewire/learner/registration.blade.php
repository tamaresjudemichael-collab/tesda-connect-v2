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
    'institution_id' => null,
    'course_id' => null,
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
            'institution_id', 'course_id', 'last_name', 'first_name', 'middle_name', 'extension_name',
            'address_line', 'barangay', 'district', 'city_municipality', 'province', 'region',
            'contact_number', 'nationality', 'sex', 'civil_status', 'employment_status',
            'employment_type', 'birthplace_city', 'birthplace_province', 'birthplace_region',
            'educational_attainment', 'guardian_name', 'guardian_address', 'classifications',
            'disability_types', 'disability_cause', 'scholarship_package', 'privacy_consent',
        ]));
        $this->birthdate = $profile->birthdate?->toDateString() ?? '';

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

    $this->institution_id = $this->institutions->first()?->id;
    [$firstName, $lastName] = array_pad(explode(' ', auth()->user()->name, 2), 2, '');
    $this->first_name = $firstName;
    $this->last_name = $lastName;
});

$updatedInstitutionId = function (): void {
    $this->course_id = null;
    unset($this->courses);
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
        <p class="text-sm font-semibold text-cyan-600">Digital MIS 03-01</p>
        <flux:heading size="xl" class="mt-1 text-3xl font-black">Learner Registration Form</flux:heading>
        <flux:text class="mt-1">Complete your learner profile. Your registration is submitted electronically for staff review.</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:card class="bg-white p-6 shadow-sm">
            <div class="mb-6"><flux:heading size="lg">1. Training preference</flux:heading><flux:text class="mt-1">Choose the institution and qualification where you are applying.</flux:text></div>
            <div class="grid gap-5 md:grid-cols-2">
                <flux:select wire:model.live="institution_id" label="Institution" required>
                    <flux:select.option value="">Select an institution</flux:select.option>
                    @foreach($this->institutions as $institution)
                        <flux:select.option value="{{ $institution->id }}">{{ $institution->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="course_id" label="Course / Qualification" required :disabled="! $institution_id">
                    <flux:select.option value="">Select a course</flux:select.option>
                    @foreach($this->courses as $course)
                        <flux:select.option value="{{ $course->id }}">{{ $course->name }}{{ $course->qualification_level ? ' · '.$course->qualification_level : '' }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </flux:card>

        <flux:card class="bg-white p-6 shadow-sm">
            <div class="mb-6"><flux:heading size="lg">2. Learner / manpower profile</flux:heading><flux:text class="mt-1">Use your complete legal name and current contact details.</flux:text></div>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4"><flux:input wire:model="last_name" label="Last name" required /><flux:input wire:model="first_name" label="First name" required /><flux:input wire:model="middle_name" label="Middle name" /><flux:input wire:model="extension_name" label="Extension (Jr., Sr.)" /></div>
            <div class="mt-5 grid gap-5 md:grid-cols-3"><flux:input wire:model="address_line" label="Number / Street" /><flux:input wire:model="barangay" label="Barangay" /><flux:input wire:model="district" label="District" /><flux:input wire:model="city_municipality" label="City / Municipality" required /><flux:input wire:model="province" label="Province" required /><flux:input wire:model="region" label="Region" required /></div>
            <div class="mt-5 grid gap-5 md:grid-cols-3"><flux:input wire:model="contact_number" label="Contact number" required /><flux:input wire:model="nationality" label="Nationality" required /><flux:input :value="auth()->user()->email" label="Email address" type="email" disabled /></div>
        </flux:card>

        <flux:card class="bg-white p-6 shadow-sm">
            <div class="mb-6"><flux:heading size="lg">3. Personal information</flux:heading><flux:text class="mt-1">The following details come from the TESDA learner profile form.</flux:text></div>
            <div class="grid gap-5 md:grid-cols-2"><flux:select wire:model="sex" label="Sex" required><flux:select.option value="">Select</flux:select.option><flux:select.option value="male">Male</flux:select.option><flux:select.option value="female">Female</flux:select.option></flux:select><flux:select wire:model="civil_status" label="Civil status" required><flux:select.option value="">Select</flux:select.option>@foreach(['Single','Married','Separated / Divorced / Annulled','Widow / Widower','Common law / Live-in'] as $option)<flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="birthdate" type="date" label="Birthdate" required /><flux:select wire:model="employment_status" label="Employment status"><flux:select.option value="">Select</flux:select.option>@foreach(['Wage-employed','Underemployed','Self-employed','Unemployed'] as $option)<flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="employment_type" label="Employment type"><flux:select.option value="">Select</flux:select.option>@foreach(['None','Regular','Casual','Job Order','Probationary','Permanent','Contractual','Temporary'] as $option)<flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="educational_attainment" label="Educational attainment" required><flux:select.option value="">Select</flux:select.option>@foreach(['No Grade Completed','Elementary Undergraduate','Elementary Graduate','High School Undergraduate','High School Graduate','Junior High (K-12)','Senior High (K-12)','Post-Secondary Non-Tertiary / TVET Course Undergraduate','Post-Secondary Non-Tertiary / TVET Course Graduate','College Undergraduate','College Graduate','Masteral','Doctorate'] as $option)<flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>@endforeach</flux:select></div>
            <div class="mt-5 grid gap-5 md:grid-cols-3"><flux:input wire:model="birthplace_city" label="Birthplace — City / Municipality" /><flux:input wire:model="birthplace_province" label="Birthplace — Province" /><flux:input wire:model="birthplace_region" label="Birthplace — Region" /></div>
            <div class="mt-5 grid gap-5 md:grid-cols-2"><flux:input wire:model="guardian_name" label="Parent / Guardian name" /><flux:input wire:model="guardian_address" label="Parent / Guardian address" /></div>
        </flux:card>

        <flux:card class="bg-white p-6 shadow-sm">
            <div class="mb-6"><flux:heading size="lg">4. Learner / trainee classification</flux:heading><flux:text class="mt-1">Select every classification that applies to you.</flux:text></div>
            <div class="grid gap-x-6 gap-y-3 md:grid-cols-3">
                @foreach([
                    '4Ps Beneficiary', 'Agrarian Reform Beneficiary', 'Balik Probinsya',
                    'Displaced Workers', 'Drug Dependents / Surrenderees', 'Family Members of AFP and PNP Killed-in-Action',
                    'Family Members of AFP and PNP Wounded-in-Action', 'Farmers and Fishermen', 'Indigenous People & Cultural Communities',
                    'Industry Workers', 'Inmates and Detainees', 'MILF Beneficiary',
                    'Out-of-School Youth', 'Overseas Filipino Workers (OFW) Dependent', 'RCEF-RESP',
                    'Rebel Returnees / Decommissioned Combatants', 'Returning / Repatriated OFW', 'Student',
                    'TESDA Alumni', 'TVET Trainers', 'Uniformed Personnel',
                    'Victim of Natural Disasters and Calamities', 'Wounded-in-Action AFP & PNP Personnel', 'Others',
                ] as $classification)
                    <flux:checkbox wire:model="classifications" value="{{ $classification }}" label="{{ $classification }}" />
                @endforeach
            </div>
            @if(in_array('Others', $classifications ?? [], true))
                <flux:input wire:model="classification_other" class="mt-5" label="Other classification — please specify" required />
            @endif
        </flux:card>

        <flux:card class="bg-white p-6 shadow-sm">
            <div class="mb-6"><flux:heading size="lg">5. Disability details</flux:heading><flux:text class="mt-1">For persons with disability only. This may be completed with authorized TESDA personnel.</flux:text></div>
            <div class="grid gap-x-6 gap-y-3 md:grid-cols-3">
                @foreach(['Mental / Intellectual', 'Visual Disability', 'Orthopedic (Musculoskeletal) Disability', 'Hearing Disability', 'Speech Impairment', 'Multiple Disabilities', 'Psychosocial Disability', 'Disability Due to Chronic Illness', 'Learning Disability'] as $type)
                    <flux:checkbox wire:model="disability_types" value="{{ $type }}" label="{{ $type }}" />
                @endforeach
            </div>
            @if(in_array('Multiple Disabilities', $disability_types ?? [], true))
                <flux:input wire:model="disability_other" class="mt-5" label="Multiple disabilities — please specify" required />
            @endif
            <flux:select wire:model="disability_cause" class="mt-5" label="Cause of disability (if applicable)">
                <flux:select.option value="">Not applicable</flux:select.option>
                <flux:select.option value="Congenital / Inborn">Congenital / Inborn</flux:select.option>
                <flux:select.option value="Illness">Illness</flux:select.option>
                <flux:select.option value="Injury">Injury</flux:select.option>
            </flux:select>
        </flux:card>

        <flux:card class="bg-white p-6 shadow-sm">
            <div class="mb-6"><flux:heading size="lg">6. Course and scholarship</flux:heading><flux:text class="mt-1">Your selected qualification is shown above. Provide a scholarship package only if applicable.</flux:text></div>
            <flux:input wire:model="scholarship_package" label="Scholarship package (TWSP, PESFA, STEP, others)" />
        </flux:card>

        <flux:card class="bg-white p-6 shadow-sm">
            <div class="mb-6"><flux:heading size="lg">7. Supporting documents</flux:heading><flux:text class="mt-1">Birth certificate is mandatory. TOR is required for High School Graduate or College Graduate. Marriage contract is required if married. Valid ID and profile photo are optional. Files are stored privately and are visible only to you and authorized TESDA staff.</flux:text></div>
            @if ($this->documentStatuses->isNotEmpty())
                @php
                    $documentLabels = [
                        'birth_certificate' => 'Birth certificate',
                        'tor' => 'Transcript of Records (TOR)',
                        'marriage_contract' => 'Marriage contract',
                        'valid_id' => 'Valid ID',
                        'profile_photo' => 'Profile photo',
                    ];
                @endphp
                <div class="mb-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <h3 class="font-semibold text-slate-900">Your latest document review</h3>
                    <p class="mt-1 text-sm text-slate-600">Check the review result below. If a document was rejected, read the staff remarks and upload a corrected replacement in this form.</p>
                    <div class="mt-3 space-y-3">
                        @foreach ($this->documentStatuses as $type => $document)
                            <div wire:key="learner-document-status-{{ $document->id }}" class="rounded-lg border border-slate-200 bg-white p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-medium text-slate-800">{{ $documentLabels[$type] ?? str($type)->headline() }}</span>
                                    @if ($document->status === 'verified')
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">Approved</span>
                                    @elseif ($document->status === 'rejected')
                                        <span class="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-800">Revision required</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">Awaiting review</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ $document->original_name }} · Submitted {{ $document->created_at?->format('M d, Y') }}</p>
                                @if ($document->status === 'rejected')
                                    <p class="mt-2 text-sm text-rose-800"><strong>Staff remarks:</strong> {{ $document->remarks ?: 'Please review the document requirements and upload a corrected copy.' }}</p>
                                    <p class="mt-1 text-sm text-slate-600">Choose a replacement file in the upload field below, then resubmit your registration.</p>
                                @elseif ($document->status === 'verified')
                                    <p class="mt-2 text-sm text-emerald-800">This document has been approved.</p>
                                @else
                                    <p class="mt-2 text-sm text-slate-600">No action is needed while staff review is in progress.</p>
                                @endif
                                <a class="mt-2 inline-block text-sm font-semibold text-cyan-700 underline" href="{{ route('learner-documents.download', $document) }}">View your submitted file</a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="grid gap-5 md:grid-cols-2">
                <flux:input wire:model="birth_certificate" type="file" label="Birth certificate (required)" accept=".jpg,.jpeg,.png,.pdf" />
                @if (in_array($educational_attainment, ['High School Graduate', 'College Graduate'], true))
                    <flux:input wire:model="tor" type="file" label="Transcript of Records (TOR) (required)" accept=".jpg,.jpeg,.png,.pdf" />
                @endif
                @if ($civil_status === 'Married')
                    <flux:input wire:model="marriage_contract" type="file" label="Marriage contract (required)" accept=".jpg,.jpeg,.png,.pdf" />
                @endif
                <flux:input wire:model="valid_id" type="file" label="Valid ID (optional)" accept=".jpg,.jpeg,.png,.pdf" />
                <flux:input wire:model="profile_photo" type="file" label="Profile photo (optional)" accept="image/jpeg,image/png" />
            </div>
            <p class="mt-3 text-xs text-slate-400">Allowed for documents: JPG, PNG, or PDF · Maximum file size: 5 MB per file.</p>
        </flux:card>

        <flux:card class="border-emerald-100 bg-emerald-50 p-6 shadow-sm"><flux:heading size="lg">8. Privacy consent and electronic submission</flux:heading><p class="mt-3 text-sm leading-6 text-emerald-900/75">I attest that the information in this learner profile is true and correct. I consent to TESDA processing my personal information for training, scholarship, employment, survey, and other related TESDA programs that may be beneficial to my qualifications.</p><flux:checkbox wire:model="privacy_consent" class="mt-5" label="Agree — I have read the Privacy Notice and consent to electronic submission." required /><div class="mt-6 flex justify-end"><flux:button type="submit" variant="primary" icon="paper-airplane">Submit learner registration</flux:button></div></flux:card>
    </form>
</div>
 