<?php

use App\Models\Course;

use App\Models\EnrollmentApplication;

use App\Models\Institution;

use App\Models\TrainingBatch;

use App\Models\User;

use Flux\Flux;

use Illuminate\Support\Str;

use Illuminate\Support\Facades\DB;

use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, layout, state, title};

use App\Models\LearnerDocument;

use App\Support\ActivityLogger;
use App\Support\EnrollmentVerificationService;
use App\Support\InstitutionScope;
use App\Support\EnrollmentStatusRecorder;

layout('layouts::app');

title('Enrollment Monitoring');

state([

    'status' => request()->query('status', 'all'),

    'formOpen' => false,

    'editingId' => null,

    'institution_id' => null,

    'course_id' => null,

    'training_batch_id' => null,

    'learner_name' => '',

    'email' => '',

    'contact_number' => '',

    'record_completeness' => 0,

    'remarks' => '',

    'documentReviewRemarks' => '',
    'viewingApplicationId' => null,

]);

$institutionIds = computed(fn () => InstitutionScope::idsFor(auth()->user()));

$institutions = computed(fn () => Institution::query()->whereIn('id', $this->institutionIds)->where('is_active', true)->orderBy('name')->get());

$courses = computed(fn () => Course::query()->where('institution_id', $this->institution_id)->where('is_active', true)->orderBy('name')->get());

$batches = computed(fn () => TrainingBatch::query()->where('institution_id', $this->institution_id)->where('course_id', $this->course_id)->orderBy('starts_at')->get());

$viewingApplication = computed(function () {
    if (! filled($this->viewingApplicationId)) {
        return null;
    }

    return EnrollmentApplication::query()
        ->with(['institution', 'course', 'trainingBatch', 'learner.learnerProfile', 'learner.learnerDocuments'])
        ->whereIn('institution_id', $this->institutionIds)
        ->whereNull('archived_at')
        ->find($this->viewingApplicationId);
});

$applications = computed(function () {

    return EnrollmentApplication::query()

->with(['institution', 'course', 'trainingBatch'])

        ->whereIn('institution_id', $this->institutionIds)
        ->whereNull('archived_at')
        ->when($this->status === 'verified_records', fn ($query) => $query->whereIn('application_status', ['verified', 'confirmed']))
        ->when(! in_array($this->status, ['all', 'verified_records'], true), fn ($query) => $query->where('application_status', $this->status))

        ->latest('submitted_at')

        ->get();

});

$pendingDocuments = computed(function () {

    return LearnerDocument::query()

        ->with(['learner', 'profile.institution'])

        ->where('status', 'pending')

        ->whereHas('profile', fn ($query) => $query

            ->whereIn('institution_id', $this->institutionIds))

        ->latest()

        ->get();

});

$reviewedDocuments = computed(function () {

    return LearnerDocument::query()

        ->with(['learner', 'reviewer', 'profile.institution'])

        ->whereNotNull('reviewed_at')

        ->whereHas('profile', fn ($query) => $query

            ->whereIn('institution_id', $this->institutionIds))

        ->latest('reviewed_at')

        ->take(20)

        ->get();

});

$resetForm = function (): void {

    $this->reset('editingId', 'institution_id', 'course_id', 'training_batch_id', 'learner_name', 'email', 'contact_number', 'record_completeness', 'remarks');

    $this->record_completeness = 0;

    $this->resetValidation();

};

$openCreate = function (): void {

    abort_unless(auth()->user()->hasRole('registrar'), 403, 'Only the Registrar can create enrollment records.');

    $this->resetForm();

    $this->institution_id = $this->institutions->first()?->id;

    $this->formOpen = true;

};

$updatedInstitutionId = function (): void {

    $this->course_id = null;

    $this->training_batch_id = null;

    unset($this->courses, $this->batches);

};

$updatedCourseId = function (): void {

    $this->training_batch_id = null;

    unset($this->batches);

};

$edit = function (int $id): void {

    abort_unless(auth()->user()->hasRole('registrar'), 403, 'Administrator access is read-only.');

    $application = EnrollmentApplication::findOrFail($id);

    abort_unless($this->institutionIds->contains($application->institution_id), 403);

    $this->resetValidation();

    $this->editingId = $application->id;

    $this->institution_id = $application->institution_id;

    $this->course_id = $application->course_id;

    $this->training_batch_id = $application->training_batch_id;

    $this->learner_name = $application->learner_name;

    $this->email = $application->email ?? '';

    $this->contact_number = $application->contact_number ?? '';

    $this->record_completeness = $application->record_completeness;

    $this->remarks = $application->remarks ?? '';

    unset($this->courses, $this->batches);

    $this->formOpen = true;

};

$save = function (): void {

    abort_unless(auth()->user()->hasRole('registrar'), 403, 'Administrator access is read-only.');

    $validated = $this->validate([

        'institution_id' => ['required', Rule::in($this->institutionIds->all())],

        'course_id' => ['required', Rule::exists('courses', 'id')->where(fn ($query) => $query->where('institution_id', $this->institution_id)->where('is_active', true))],

        'training_batch_id' => ['nullable', Rule::exists('training_batches', 'id')->where(fn ($query) => $query->where('institution_id', $this->institution_id)->where('course_id', $this->course_id))],

        'learner_name' => ['required', 'string', 'max:255'],

        'email' => ['nullable', 'email', 'max:255'],

        'contact_number' => ['nullable', 'string', 'max:30'],

        'record_completeness' => ['required', 'integer', 'min:0', 'max:100'],

        'remarks' => ['nullable', 'string', 'max:2000'],

    ]);

    if ($this->editingId) {

        $application = EnrollmentApplication::findOrFail($this->editingId);

        abort_unless($this->institutionIds->contains($application->institution_id), 403);

        $application->update($validated);
ActivityLogger::record(
    'enrollment_updated',
    'Updated enrollment application '.$application->reference_no,
    (int) $application->institution_id,
    [
        'application_id' => $application->id,
        'reference_no' => $application->reference_no,
        'course_id' => $application->course_id,
        'training_batch_id' => $application->training_batch_id,
    ]
);
    } else {

        $application = EnrollmentApplication::create([

            ...$validated,

            'reference_no' => 'TC-'.now()->format('Y').'-'.strtoupper((string) Str::ulid()),

            'application_status' => 'draft',

            'submitted_at' => null,

        ]);

        EnrollmentStatusRecorder::record($application, null, 'draft', 'Staff-created enrollment record.');

        ActivityLogger::record(
    'enrollment_created',
    'Created enrollment application '.$application->reference_no,
    (int) $application->institution_id,
    [
        'application_id' => $application->id,
        'reference_no' => $application->reference_no,
        'course_id' => $application->course_id,
        'training_batch_id' => $application->training_batch_id,
    ]
);

    }

    unset($this->applications);

    $this->resetForm();

    $this->formOpen = false;

    Flux::toast(variant: 'success', text: 'Enrollment record saved.');

};

$verify = function (int $id): void {

    abort_unless(auth()->user()->hasRole('registrar'), 403, 'Only the Registrar can verify enrollment records.');

    $application = EnrollmentApplication::findOrFail($id);

    abort_unless($this->institutionIds->contains($application->institution_id), 403);

    if (! in_array($application->application_status, ['submitted', 'under_review'], true)) {
        Flux::toast(
            variant: 'warning',
            text: 'This application is not ready for verification. Only submitted applications can be verified.'
        );

        return;
    }

    $fromStatus = $application->application_status;
    $assessment = app(EnrollmentVerificationService::class)->assess($application);

    // Keep the completeness indicator current even when the requirements are incomplete.
    $application->update(['record_completeness' => $assessment['percentage']]);

    if (! $assessment['complete']) {
        Flux::toast(
            variant: 'warning',
            text: 'Cannot verify this application yet. Please complete the following: '.implode('; ', $assessment['missing'])
        );

        return;
    }

    $application->update([
        'application_status' => 'verified',
        'verified_at' => now(),
        'record_completeness' => 100,
    ]);

    EnrollmentStatusRecorder::record($application, $fromStatus, 'verified', 'Required profile fields and supporting documents were verified.');

    ActivityLogger::record(

    'enrollment_verified',

    'Verified enrollment application '.$application->reference_no,

    (int) $application->institution_id,

    [

        'application_id' => $application->id,

        'reference_no' => $application->reference_no,

    ]

);

    unset($this->applications);

    Flux::toast(variant: 'success', text: 'Application verified.');

};

$confirm = function (int $id): void {

    abort_unless(auth()->user()->hasRole('registrar'), 403, 'Administrator access is read-only.');

    $scholarId = DB::transaction(function () use ($id): string {

        $application = EnrollmentApplication::query()

            ->whereKey($id)

            ->lockForUpdate()

            ->firstOrFail();

        abort_unless($this->institutionIds->contains($application->institution_id), 403);

        if ($application->application_status !== 'verified' || $application->verified_at === null || $application->confirmed_at !== null) {

            abort(422, 'Only verified applications that have not yet been confirmed can be confirmed.');

        }

        if (! $application->learner_user_id) {

            abort(422, 'This application is not linked to a registered learner account. Link the learner account before confirmation.');

        }

        $learner = User::query()

            ->whereKey($application->learner_user_id)

            ->lockForUpdate()

            ->firstOrFail();

        abort_unless($learner->account_status === 'active', 422, 'The learner account must be active before confirmation.');

        abort_unless(in_array($learner->role, ['applicant', 'scholar'], true), 422, 'Only applicant accounts can be confirmed as scholars.');

        $institution = Institution::query()

            ->whereKey($application->institution_id)

            ->lockForUpdate()

            ->firstOrFail();

        $code = strtoupper(trim((string) $institution->code));

        abort_unless(preg_match('/^[A-Z0-9]{2,10}$/', $code) === 1, 422, 'The institution code is invalid for Scholar ID generation.');

        $year = now()->format('Y');

        $prefix = $code.'-'.$year.'-';

        $usedIds = EnrollmentApplication::query()

            ->where('institution_id', $institution->id)

            ->whereNotNull('scholar_id')

            ->where('scholar_id', 'like', $prefix.'%')

            ->pluck('scholar_id');

        $highestNumber = $usedIds->reduce(function (int $highest, string $usedId) use ($prefix): int {

            if (! str_starts_with($usedId, $prefix)) {

                return $highest;

            }

            $suffix = substr($usedId, strlen($prefix));

            return ctype_digit($suffix) ? max($highest, (int) $suffix) : $highest;

        }, 0);

        $newScholarId = $prefix.str_pad((string) ($highestNumber + 1), 4, '0', STR_PAD_LEFT);

        if (EnrollmentApplication::query()->where('scholar_id', $newScholarId)->exists()) {

            abort(409, 'The generated Scholar ID already exists. Please retry the confirmation.');

        }

        $application->update([

            'application_status' => 'confirmed',

            'confirmed_at' => now(),

            'scholar_id' => $newScholarId,

        ]);

        EnrollmentStatusRecorder::record($application, 'verified', 'confirmed', 'Scholar ID issued: '.$newScholarId);

        $learner->institutions()->syncWithoutDetaching([

            $institution->id => ['is_primary' => true],

        ]);

        if ($learner->role === 'applicant') {

            $learner->forceFill(['role' => 'scholar'])->save();

        }

        ActivityLogger::record(
            'scholar_confirmed',
            'Confirmed enrollment '.$application->reference_no.' and issued Scholar ID '.$newScholarId,
            (int) $application->institution_id,
            [
                'application_id' => $application->id,
                'reference_no' => $application->reference_no,
                'scholar_id' => $newScholarId,
            ]
        );

        return $newScholarId;

    }, 3);

    unset($this->applications);

    Flux::toast(variant: 'success', text: 'Enrollment confirmed. Scholar ID: '.$scholarId);

};

$returnForRevision = function (int $id): void {

    abort_unless(auth()->user()->hasRole('registrar'), 403, 'Administrator access is read-only.');

    $application = EnrollmentApplication::findOrFail($id);

    abort_unless($this->institutionIds->contains($application->institution_id), 403);

    abort_unless(

    in_array($application->application_status, ['submitted', 'under_review'], true),

    422,

    'Only submitted applications can be returned for revision.'

);

    $fromStatus = $application->application_status;
    $revisionReason = 'Please review and complete the missing registration requirements.';
    $application->update(['application_status' => 'returned_for_revision', 'remarks' => $revisionReason]);
    EnrollmentStatusRecorder::record($application, $fromStatus, 'returned_for_revision', $revisionReason);
ActivityLogger::record(
    'enrollment_returned',
    'Returned enrollment application '.$application->reference_no.' for revision',
    (int) $application->institution_id,
    [
        'application_id' => $application->id,
        'reference_no' => $application->reference_no,
        'remarks' => $application->remarks,
    ]
);
    unset($this->applications);

    Flux::toast(variant: 'warning', text: 'Application returned for revision.');

};

$delete = function (int $id): void {

    abort_unless(auth()->user()->hasRole('registrar'), 403, 'Administrator access is read-only.');

    $application = EnrollmentApplication::findOrFail($id);

    abort_unless($this->institutionIds->contains($application->institution_id), 403);

    abort_unless(

    $application->application_status !== 'confirmed',

    422,

    'Confirmed scholar records cannot be deleted.'

);

$applicationId = $application->id;
$referenceNo = $application->reference_no;
$institutionId = (int) $application->institution_id;
EnrollmentStatusRecorder::record($application, $application->application_status, 'deleted', 'Enrollment application deleted by authorized staff.');

$application->delete();

ActivityLogger::record(
    'enrollment_deleted',
    'Deleted enrollment application '.$referenceNo,
    $institutionId,
    [
        'application_id' => $applicationId,
        'reference_no' => $referenceNo,
    ]
);
    unset($this->applications);

    Flux::toast(variant: 'success', text: 'Enrollment record deleted.');

};

$verifyDocument = function (int $id): void {

    abort_unless(auth()->user()->hasRole('registrar'), 403, 'Administrator access is read-only.');

    $document = LearnerDocument::query()

        ->with('profile')

        ->findOrFail($id);

    $institutionId = $document->profile?->institution_id;

    abort_unless(

        $institutionId && $this->institutionIds->contains($institutionId),

        403

    );

    abort_unless($document->status === 'pending', 422);

    $document->update([

        'status' => 'verified',

        'reviewed_by_user_id' => auth()->id(),

        'reviewed_at' => now(),

        'remarks' => null,

    ]);
ActivityLogger::record(
    'document_verified',
    'Verified learner document '.$document->original_name,
    (int) $institutionId,
    [
        'document_id' => $document->id,
        'document_type' => $document->document_type,
    ]
);
    unset($this->pendingDocuments);

    Flux::toast(variant: 'success', text: 'Document verified successfully.');

};

$rejectDocument = function (int $id): void {

    abort_unless(auth()->user()->hasRole('registrar'), 403, 'Administrator access is read-only.');

    $validated = $this->validate([

        'documentReviewRemarks' => ['required', 'string', 'max:2000'],

    ]);

    $document = LearnerDocument::query()

        ->with('profile')

        ->findOrFail($id);

    $institutionId = $document->profile?->institution_id;

    abort_unless(

        $institutionId && $this->institutionIds->contains($institutionId),

        403

    );

    abort_unless($document->status === 'pending', 422);

    $document->update([

        'status' => 'rejected',

        'reviewed_by_user_id' => auth()->id(),

        'reviewed_at' => now(),

        'remarks' => trim($validated['documentReviewRemarks']),

    ]);
ActivityLogger::record(
    'document_rejected',
    'Rejected learner document '.$document->original_name,
    (int) $institutionId,
    [
        'document_id' => $document->id,
        'document_type' => $document->document_type,
        'remarks' => trim($validated['documentReviewRemarks']),
    ]
);
    $this->documentReviewRemarks = '';

    unset($this->pendingDocuments);

    Flux::toast(variant: 'warning', text: 'Document rejected. Review remarks saved.');

};

?>

<div class="mx-auto max-w-7xl space-y-6">

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div>@if (auth()->user()->hasRole('administrator'))<p class="text-sm font-semibold text-blue-700">Administrator oversight · Read only</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Student Enrollment Submissions</flux:heading><flux:text class="mt-1">View enrollment submissions across all institutions. Review, verification, confirmation, edits, and document decisions are reserved for the Registrar.</flux:text>@else<p class="text-sm font-semibold text-cyan-600">Registrar workspace</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Enrollment Monitoring</flux:heading><flux:text class="mt-1">Review student-submitted MIS 03-01 registrations, verify requirements, and advance the enrollment workflow.</flux:text>@endif</div>@if (auth()->user()->hasRole('registrar'))<flux:button variant="primary" icon="user-plus" wire:click="openCreate">New learner record</flux:button>@endif</div>

    @if ($this->viewingApplication)
        @php($detail = $this->viewingApplication)
        @php($profile = $detail->learner?->learnerProfile)
        <flux:card class="border-blue-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><p class="text-xs font-bold uppercase tracking-wide text-blue-700">Read-only application details</p><flux:heading size="lg" class="mt-1">{{ $detail->learner_name }}</flux:heading><flux:text class="mt-1">{{ $detail->reference_no }} · {{ $detail->institution?->code ?? 'No institution' }}</flux:text></div>
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('viewingApplicationId', null)">Close</flux:button>
            </div>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    'Email' => $detail->email ?: 'Not recorded',
                    'Contact number' => $detail->contact_number ?: 'Not recorded',
                    'Institution' => $detail->institution?->name ?: 'Not assigned',
                    'Course' => $detail->course?->name ?: 'Not assigned',
                    'Training batch' => $detail->trainingBatch?->name ?: 'Not assigned',
                    'Status' => str($detail->application_status)->headline(),
                    'Submitted' => $detail->submitted_at?->format('M j, Y g:i A') ?: 'Not submitted',
                    'Scholar ID' => $detail->scholar_id ?: 'Not issued',
                    'Record completeness' => $detail->record_completeness.'%',
                    'Address' => $profile ? collect([$profile->address_line, $profile->barangay, $profile->city_municipality, $profile->province, $profile->region])->filter()->join(', ') : 'Not recorded',
                    'Birth date' => $profile?->birthdate?->format('M j, Y') ?: 'Not recorded',
                    'Educational attainment' => $profile?->educational_attainment ?: 'Not recorded',
                    'Sex' => $profile?->sex ? str($profile->sex)->headline() : 'Not recorded',
                    'Civil status' => $profile?->civil_status ?: 'Not recorded',
                    'Employment status' => $profile?->employment_status ?: 'Not recorded',
                    'Remarks' => $detail->remarks ?: 'None',
                ] as $label => $value)
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-800">{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if ($detail->learner?->learnerDocuments?->isNotEmpty())
                <div class="mt-4"><flux:heading size="sm">Uploaded documents</flux:heading><div class="mt-2 flex flex-wrap gap-2">@foreach ($detail->learner->learnerDocuments as $document)<a href="{{ route('learner-documents.download', $document->id) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-slate-50">{{ $document->original_name }} · {{ str($document->status)->headline() }}</a>@endforeach</div></div>
            @endif
            @if (auth()->user()->hasRole('administrator'))<p class="mt-4 text-xs font-semibold text-slate-500">Read-only view. Only the Registrar may act on this application.</p>@endif
        </flux:card>
    @endif

    @if (auth()->user()->hasRole('registrar') && $formOpen)

        <flux:card class="bg-white p-6 shadow-sm"><div class="flex items-start justify-between gap-4"><div><flux:heading size="lg">{{ $editingId ? 'Edit learner record' : 'New learner record' }}</flux:heading><flux:text class="mt-1">This creates a staff-entered intake record. Learners can later complete their full MIS 03-01 profile.</flux:text></div><flux:button variant="ghost" icon="x-mark" wire:click="$set('formOpen', false)" /></div><form wire:submit="save" class="mt-6 grid gap-5 md:grid-cols-2 lg:grid-cols-3"><flux:select wire:model.live="institution_id" label="Institution" required><flux:select.option value="">Select institution</flux:select.option>@foreach($this->institutions as $institution)<flux:select.option value="{{ $institution->id }}">{{ $institution->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model.live="course_id" label="Course" required><flux:select.option value="">Select course</flux:select.option>@foreach($this->courses as $course)<flux:select.option value="{{ $course->id }}">{{ $course->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="training_batch_id" label="Batch"><flux:select.option value="">Not assigned yet</flux:select.option>@foreach($this->batches as $batch)<flux:select.option value="{{ $batch->id }}">{{ $batch->name }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="learner_name" label="Learner name" required /><flux:input wire:model="email" type="email" label="Email address" /><flux:input wire:model="contact_number" label="Contact number" /><flux:input wire:model="record_completeness" type="number" min="0" max="100" label="Record completeness (%)" required /><flux:textarea wire:model="remarks" label="Remarks" class="lg:col-span-3" /><div class="flex justify-end gap-3 lg:col-span-3"><flux:button variant="ghost" type="button" wire:click="resetForm">Reset</flux:button><flux:button variant="primary" type="submit">Save record</flux:button></div></form>

    </flux:card>

    @endif

@if (auth()->user()->hasRole('administrator', 'registrar'))

    <flux:card class="bg-white p-6 shadow-sm">

        <div>

            <flux:heading size="lg">Document Review History</flux:heading>

            <flux:text class="mt-1">

                View recently reviewed learner documents and their review results.

            </flux:text>

        </div>

        <div class="mt-5 overflow-x-auto">

            <table class="w-full min-w-[900px] text-left text-sm">

                <thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400">

                    <tr>

                        <th class="px-3 py-3">Learner</th>

                        <th class="px-3 py-3">Document</th>

                        <th class="px-3 py-3">Result</th>

                        <th class="px-3 py-3">Reviewed by</th>

                        <th class="px-3 py-3">Review date</th>

                        <th class="px-3 py-3">Remarks</th>

                    </tr>

                </thead>

                <tbody class="divide-y divide-slate-100">

                    @forelse ($this->reviewedDocuments as $document)

                        <tr>

                            <td class="px-3 py-4">

                                <p class="font-semibold text-slate-800">

                                    {{ $document->learner?->name ?? 'Unknown learner' }}

                                </p>

                                <p class="mt-1 text-xs text-slate-500">

                                    {{ $document->profile?->institution?->name ?? 'Institution not assigned' }}

                                </p>

                            </td>

                            <td class="px-3 py-4">

                                <p class="font-semibold text-slate-800">

                                    {{ str($document->document_type)->headline() }}

                                </p>

                                <p class="mt-1 break-all text-xs text-slate-500">

                                    {{ $document->original_name }}

                                </p>

                            </td>

                            <td class="px-3 py-4">

                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold

                                    {{ $document->status === 'verified'

                                        ? 'bg-emerald-50 text-emerald-700'

                                        : 'bg-rose-50 text-rose-700' }}">

                                    {{ str($document->status)->headline() }}

                                </span>

                            </td>

                            <td class="px-3 py-4 text-slate-600">

                                {{ $document->reviewer?->name ?? 'Unknown reviewer' }}

                            </td>

                            <td class="px-3 py-4 text-slate-600">

                                {{ $document->reviewed_at?->format('M d, Y h:i A') ?? '—' }}

                            </td>

                            <td class="px-3 py-4 text-slate-600">

                                <span class="block max-w-xs whitespace-normal break-words">

                                    {{ $document->remarks ?: '—' }}

                                </span>

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td colspan="6" class="px-3 py-10 text-center text-slate-400">

                                No reviewed documents yet.

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </flux:card>

@endif

    <flux:card class="bg-white p-5 shadow-sm">

        @if (auth()->user()->hasRole('administrator', 'registrar'))

    <flux:card class="bg-white p-6 shadow-sm">

        <div>

            <flux:heading size="lg">Learner Document Review</flux:heading>

            <flux:text class="mt-1">

                Review uploaded learner documents for your assigned institution.

            </flux:text>

        </div>

        @if (auth()->user()->hasRole('registrar'))
            <div class="mt-5">
                <flux:textarea wire:model="documentReviewRemarks" label="Rejection remarks" placeholder="Enter the reason if rejecting a document." />
                @error('documentReviewRemarks')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-slate-500">Remarks are required when rejecting a document.</p>
            </div>
        @endif

        <div class="mt-5 overflow-x-auto">

            <table class="w-full min-w-[800px] text-left text-sm">

                <thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400">

                    <tr>

                        <th class="px-3 py-3">Learner</th>

                        <th class="px-3 py-3">Document</th>

                        <th class="px-3 py-3">File size</th>

                        <th class="px-3 py-3">Status</th>

                        <th class="px-3 py-3 text-right">{{ auth()->user()->hasRole('registrar') ? 'Actions' : 'Access' }}</th>

                    </tr>

                </thead>

                <tbody class="divide-y divide-slate-100">

                    @forelse ($this->pendingDocuments as $document)

                        <tr>

                            <td class="px-3 py-4">

                                <p class="font-semibold text-slate-800">

                                    {{ $document->learner?->name ?? 'Unknown learner' }}

                                </p>

                                <p class="mt-1 text-xs text-slate-500">

                                    {{ $document->profile?->institution?->name ?? 'Institution not assigned' }}

                                </p>

                            </td>

                            <td class="px-3 py-4">

                                <p class="font-semibold text-slate-800">

                                    {{ str($document->document_type)->headline() }}

                                </p>

                                <p class="mt-1 break-all text-xs text-slate-500">

                                    {{ $document->original_name }}

                                </p>

                                <a

                                    href="{{ route('learner-documents.download', $document->id) }}"

                                    class="mt-2 inline-flex text-sm font-semibold text-blue-700 hover:text-blue-900"

                                >

                                    Download file

                                </a>

                            </td>

                            <td class="px-3 py-4 text-slate-600">

                                {{ number_format($document->size / 1024, 0) }} KB

                            </td>

                            <td class="px-3 py-4">

                                <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-700">

                                    Pending

                                </span>

                            </td>

                            <td class="px-3 py-4">
                                @if (auth()->user()->hasRole('registrar'))
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" variant="primary" wire:click="verifyDocument({{ $document->id }})" wire:confirm="Verify this document?">Verify</flux:button>
                                        <flux:button size="sm" variant="danger" wire:click="rejectDocument({{ $document->id }})" wire:confirm="Reject this document using the remarks above?">Reject</flux:button>
                                    </div>
                                @else
                                    <div class="flex justify-end"><span class="text-xs font-semibold text-slate-400">Read only</span></div>
                                @endif
                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td colspan="6" class="px-3 py-10 text-center text-slate-400">

                                No pending learner documents for your assigned institution.

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </flux:card>

@endif

    <div class="flex flex-wrap items-center gap-2"><span class="mr-2 text-sm font-semibold text-slate-600">Filter:</span>@foreach (['all' => 'All records', 'submitted' => 'Submitted', 'returned_for_revision' => 'For revision', 'verified' => 'Verified', 'confirmed' => 'Confirmed'] as $value => $label)<button wire:click="$set('status', '{{ $value }}')" class="rounded-full px-3 py-1.5 text-sm font-medium {{ $status === $value ? 'bg-[#102f68] text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">{{ $label }}</button>@endforeach</div><div class="mt-5 overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm"><thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400"><tr>

        <th class="px-3 py-3">Learner</th><th class="px-3 py-3">Institution</th><th class="px-3 py-3">Course / Batch</th>

        <th class="px-3 py-3">Completeness</th><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">{{ auth()->user()->hasRole('registrar') ? 'Actions' : 'View' }}</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse($this->applications as $application)<tr><td class="px-3 py-4"><p class="font-semibold text-slate-800">{{ $application->learner_name }}</p><p class="mt-0.5 text-xs text-slate-400">{{ $application->reference_no }} · {{ $application->email }}</p>

        </td><td class="px-3 py-4"><p class="font-semibold text-slate-800">{{ $application->institution?->code ?? 'N/A' }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $application->institution?->name ?? 'Institution not assigned' }}</p></td><td class="px-3 py-4 text-slate-600">{{ $application->course?->name ?? 'Course pending' }}

            <p class="mt-0.5 text-xs text-slate-400">{{ $application->trainingBatch?->name ?? 'Batch not assigned' }}</p></td><td class="px-3 py-4"><div class="flex items-center gap-2"><div class="h-2 w-20 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $application->record_completeness === 100 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $application->record_completeness }}%"></div></div><span class="text-xs font-bold text-slate-600">{{ $application->record_completeness }}%</span></div></td><td class="px-3 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ in_array($application->application_status, ['verified','confirmed']) ? 'bg-emerald-50 text-emerald-700' : ($application->application_status === 'returned_for_revision' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">{{ Str::headline($application->application_status) }}</span>@if($application->forwarding_id)<p class="mt-1 text-xs font-bold text-emerald-700">Ready for Scheduling</p>@endif</td><td class="px-3 py-4 text-right"><div class="flex flex-wrap justify-end gap-2"><flux:button size="sm" variant="ghost" wire:click="$set('viewingApplicationId', {{ $application->id }})">View details</flux:button>@if (auth()->user()->hasRole('registrar'))@if(in_array($application->application_status, ['under_review', 'submitted']))<flux:button size="sm" variant="primary" wire:click="verify({{ $application->id }})">Verify</flux:button><flux:button size="sm" variant="ghost" wire:click="returnForRevision({{ $application->id }})">Return</flux:button>@endif @if($application->application_status === 'verified' && ! $application->confirmed_at)<flux:button size="sm" variant="primary" wire:click="confirm({{ $application->id }})" wire:confirm="Confirm this verified enrollment and issue a Scholar ID?">Confirm enrollment</flux:button>@endif <flux:button size="sm" variant="filled" wire:click="edit({{ $application->id }})">Edit</flux:button>@if ($application->application_status !== 'confirmed')<flux:button size="sm" variant="danger" wire:click="delete({{ $application->id }})" wire:confirm="Delete {{ $application->learner_name }}'s record? This cannot be undone.">Delete</flux:button>@endif @else<span class="self-center text-xs font-semibold text-slate-400">Read only</span>@endif</div></td></tr>@empty<tr><td colspan="6" class="px-3 py-12 text-center text-slate-400">No enrollment records match this filter.</td></tr>@endforelse</tbody></table></div>

</flux:card>

</div>
