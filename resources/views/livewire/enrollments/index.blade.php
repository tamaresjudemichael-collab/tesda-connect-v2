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
    'selectedReviewLearnerId' => null,
    'selectedApprovalId' => null,
    'selectedReturnId' => null,
    'selectedDeleteId' => null,
    'selectedDocumentApprovalId' => null,
    'selectedDocumentRejectionId' => null,
    'search' => '',

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

    $this->selectedApprovalId = null;
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

    $this->selectedReturnId = null;
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

    $this->selectedDeleteId = null;
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
    unset($this->pendingDocuments, $this->reviewLearner, $this->reviewHistory);

    $this->selectedDocumentApprovalId = null;
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

    unset($this->pendingDocuments, $this->reviewLearner, $this->reviewHistory);

    $this->selectedDocumentRejectionId = null;
    Flux::toast(variant: 'warning', text: 'Document rejected. Review remarks saved.');

};



$reviewLearner = computed(function () {
    if (! $this->selectedReviewLearnerId) return null;

    return User::query()
        ->with(['learnerProfile.institution', 'learnerDocuments.reviewer'])
        ->whereKey($this->selectedReviewLearnerId)
        ->whereHas('learnerProfile', fn ($query) => $query->whereIn('institution_id', $this->institutionIds))
        ->first();
});

$reviewHistory = computed(function () {
    if (! $this->selectedReviewLearnerId) return collect();

    return LearnerDocument::query()
        ->with('reviewer')
        ->where('user_id', $this->selectedReviewLearnerId)
        ->whereNotNull('reviewed_at')
        ->latest('reviewed_at')
        ->get();
});

$openReview = function (int $learnerId): void {
    abort_unless(auth()->user()->hasRole('administrator', 'registrar'), 403);

    $this->selectedReviewLearnerId = $learnerId;
    $this->documentReviewRemarks = '';
    $this->resetValidation();
};

$closeReview = function (): void {
    $this->selectedReviewLearnerId = null;
    $this->documentReviewRemarks = '';
    $this->resetValidation();
};

?>

<div class="mx-auto max-w-7xl space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            @if (auth()->user()->hasRole('administrator'))
                <p class="text-sm font-semibold text-blue-700">Administrator oversight · Read only</p>
                <flux:heading size="xl">Student Enrollment Submissions</flux:heading>
                <flux:text>Monitor enrollment submissions across your accessible institutions.</flux:text>
            @else
                <p class="text-sm font-semibold text-cyan-700">Registrar workspace</p>
                <flux:heading size="xl">Student Enrollment Submissions</flux:heading>
                <flux:text>Review applications, learner documents, and enrollment decisions.</flux:text>
            @endif
        </div>
        @if (auth()->user()->hasRole('registrar'))
            <flux:button variant="primary" icon="user-plus" wire:click="openCreate">New learner record</flux:button>
        @endif
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([['All records', $this->applications->count()], ['Pending review', $this->applications->whereIn('application_status', ['submitted','under_review'])->count()], ['Verified', $this->applications->where('application_status','verified')->count()], ['Confirmed', $this->applications->where('application_status','confirmed')->count()]] as [$label, $count])
            <flux:card class="p-4"><flux:text>{{ $label }}</flux:text><flux:heading size="xl" class="mt-1">{{ $count }}</flux:heading></flux:card>
        @endforeach
    </div>

    <flux:card class="space-y-4 p-4 sm:p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading size="lg">Enrollment applications</flux:heading>
            <div class="w-full sm:max-w-xs"><flux:input wire:model.live.debounce.300ms="search" placeholder="Search learner, reference, email..." icon="magnifying-glass" /></div>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach (['all' => 'All', 'submitted' => 'Submitted', 'returned_for_revision' => 'For revision', 'verified' => 'Verified', 'confirmed' => 'Confirmed'] as $value => $label)
                <button wire:click="$set('status', '{{ $value }}')" class="rounded-full px-3 py-1.5 text-sm font-medium {{ $status === $value ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[950px] text-left text-sm">
                <thead class="border-b text-xs uppercase tracking-wide text-slate-500"><tr>
                    <th class="px-3 py-3">Learner</th><th class="px-3 py-3">Institution</th><th class="px-3 py-3">Course / Batch</th><th class="px-3 py-3">Completeness</th><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Actions</th>
                </tr></thead>
                <tbody class="divide-y">
                    @forelse ($this->applications->filter(fn ($application) => blank($search) || str($application->learner_name.' '.$application->reference_no.' '.$application->email)->lower()->contains(str($search)->lower())) as $application)
                        <tr wire:key="enrollment-{{ $application->id }}">
                            <td class="px-3 py-4"><div class="font-semibold text-slate-900">{{ $application->learner_name }}</div><div class="mt-1 text-xs text-slate-500">{{ $application->reference_no }} · {{ $application->email ?: 'No email' }}</div></td>
                            <td class="px-3 py-4"><div class="font-medium">{{ $application->institution?->code ?? 'N/A' }}</div><div class="text-xs text-slate-500">{{ $application->institution?->name ?? 'Not assigned' }}</div></td>
                            <td class="px-3 py-4"><div>{{ $application->course?->name ?? 'Course pending' }}</div><div class="text-xs text-slate-500">{{ $application->trainingBatch?->name ?? 'Batch not assigned' }}</div></td>
                            <td class="px-3 py-4"><div class="flex items-center gap-2"><div class="h-2 w-16 overflow-hidden rounded-full bg-slate-100"><div class="h-full bg-emerald-500" style="width: {{ $application->record_completeness }}%"></div></div><span class="text-xs font-semibold">{{ $application->record_completeness }}%</span></div></td>
                            <td class="px-3 py-4"><flux:badge color="{{ in_array($application->application_status, ['verified','confirmed']) ? 'green' : ($application->application_status === 'returned_for_revision' ? 'red' : 'amber') }}">{{ str($application->application_status)->headline() }}</flux:badge>@if($application->forwarding_id)<div class="mt-1 text-xs font-semibold text-emerald-700">Ready for Scheduling</div>@endif</td>
                            <td class="px-3 py-4"><div class="flex flex-wrap justify-end gap-1.5">
                                <flux:button size="sm" variant="ghost" wire:click="$set('viewingApplicationId', {{ $application->id }})" icon="eye">View</flux:button>
                                @if ($application->learner_user_id)
                                    <flux:button size="sm" variant="outline" wire:click="openReview({{ $application->learner_user_id }})">Documents</flux:button>
                                @endif
                                @if (auth()->user()->hasRole('registrar'))
                                    @if(in_array($application->application_status, ['under_review','submitted']))
                                        <flux:button size="sm" variant="primary" wire:click="verify({{ $application->id }})">Verify</flux:button>
                                        <flux:button size="sm" variant="ghost" wire:click="$set('selectedReturnId', {{ $application->id }})">Return</flux:button>
                                    @endif
                                    @if($application->application_status === 'verified' && ! $application->confirmed_at)
                                        <flux:button size="sm" variant="primary" wire:click="$set('selectedApprovalId', {{ $application->id }})">Approve</flux:button>
                                    @endif
                                    <flux:button size="sm" variant="outline" wire:click="edit({{ $application->id }})">Edit</flux:button>
                                    @if ($application->application_status !== 'confirmed')
                                        <flux:button size="sm" variant="danger" wire:click="$set('selectedDeleteId', {{ $application->id }})">Delete</flux:button>
                                    @endif
                                @endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-12 text-center text-slate-500">No enrollment records match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

    {{-- Create / Edit modal --}}
    @if (auth()->user()->hasRole('registrar') && $formOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-3 sm:p-6" wire:key="enrollment-form-modal">
            <div class="max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-7">
                <div class="flex items-start justify-between gap-4"><div><flux:heading size="lg">{{ $editingId ? 'Edit learner record' : 'New learner record' }}</flux:heading><flux:text>Staff-entered enrollment information.</flux:text></div><flux:button variant="ghost" icon="x-mark" wire:click="$set('formOpen', false)" /></div>
                <form wire:submit="save" class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <flux:select wire:model.live="institution_id" label="Institution" required><flux:select.option value="">Select institution</flux:select.option>@foreach($this->institutions as $institution)<flux:select.option value="{{ $institution->id }}">{{ $institution->name }}</flux:select.option>@endforeach</flux:select>
                    <flux:select wire:model.live="course_id" label="Course" required><flux:select.option value="">Select course</flux:select.option>@foreach($this->courses as $course)<flux:select.option value="{{ $course->id }}">{{ $course->name }}</flux:select.option>@endforeach</flux:select>
                    <flux:select wire:model="training_batch_id" label="Batch"><flux:select.option value="">Not assigned yet</flux:select.option>@foreach($this->batches as $batch)<flux:select.option value="{{ $batch->id }}">{{ $batch->name }}</flux:select.option>@endforeach</flux:select>
                    <flux:input wire:model="learner_name" label="Learner name" required /><flux:input wire:model="email" type="email" label="Email address" /><flux:input wire:model="contact_number" label="Contact number" /><flux:input wire:model="record_completeness" type="number" min="0" max="100" label="Record completeness (%)" required /><div class="sm:col-span-2 lg:col-span-3"><flux:textarea wire:model="remarks" label="Remarks" /></div>
                    <div class="flex justify-end gap-2 sm:col-span-2 lg:col-span-3"><flux:button type="button" variant="ghost" wire:click="$set('formOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Save record</flux:button></div>
                </form>
            </div>
        </div>
    @endif

    {{-- Application details modal --}}
    @if ($this->viewingApplication)
        @php($detail = $this->viewingApplication)
        @php($profile = $detail->learner?->learnerProfile)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-3 sm:p-6">
            <div class="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-7">
                <div class="flex items-start justify-between"><div><flux:heading size="lg">{{ $detail->learner_name }}</flux:heading><flux:text>{{ $detail->reference_no }} · {{ $detail->institution?->name }}</flux:text></div><flux:button variant="ghost" icon="x-mark" wire:click="$set('viewingApplicationId', null)" /></div>
                <dl class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (['Email' => $detail->email ?: 'Not recorded', 'Contact number' => $detail->contact_number ?: 'Not recorded', 'Institution' => $detail->institution?->name ?: 'Not assigned', 'Course' => $detail->course?->name ?: 'Not assigned', 'Training batch' => $detail->trainingBatch?->name ?: 'Not assigned', 'Status' => str($detail->application_status)->headline(), 'Submitted' => $detail->submitted_at?->format('M j, Y g:i A') ?: 'Not submitted', 'Scholar ID' => $detail->scholar_id ?: 'Not issued', 'Completeness' => $detail->record_completeness.'%', 'Address' => $profile ? collect([$profile->address_line,$profile->barangay,$profile->city_municipality,$profile->province,$profile->region])->filter()->join(', ') : 'Not recorded', 'Birth date' => $profile?->birthdate?->format('M j, Y') ?: 'Not recorded', 'Educational attainment' => $profile?->educational_attainment ?: 'Not recorded', 'Remarks' => $detail->remarks ?: 'None'] as $label => $value)
                        <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-900">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                <div class="mt-5 flex justify-end"><flux:button variant="primary" wire:click="$set('viewingApplicationId', null)">Close</flux:button></div>
            </div>
        </div>
    @endif

    {{-- Per-learner document review modal with history tab --}}
    @if ($this->reviewLearner)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-3 sm:p-6" wire:key="learner-review-{{ $selectedReviewLearnerId }}">
            <div class="max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-7">
                <div class="flex items-start justify-between"><div><flux:heading size="lg">Learner Document Review</flux:heading><flux:text>{{ $this->reviewLearner->name }} · {{ $this->reviewLearner->learnerProfile?->institution?->name }}</flux:text></div><flux:button variant="ghost" icon="x-mark" wire:click="closeReview" /></div>
                <div class="mt-5 grid gap-3 sm:grid-cols-2"><flux:card class="p-3"><flux:text>Uploaded documents</flux:text><flux:heading size="xl">{{ $this->reviewLearner->learnerDocuments->count() }}</flux:heading></flux:card><flux:card class="p-3"><flux:text>Awaiting review</flux:text><flux:heading size="xl">{{ $this->reviewLearner->learnerDocuments->where('status','pending')->count() }}</flux:heading></flux:card></div>
                <div class="mt-5"><flux:heading size="sm">Submitted documents</flux:heading><div class="mt-3 space-y-3">
                    @forelse($this->reviewLearner->learnerDocuments as $document)
                        <div class="flex flex-col gap-3 rounded-xl border border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><div class="font-semibold text-slate-900">{{ str($document->document_type)->headline() }}</div><div class="break-all text-sm text-slate-500">{{ $document->original_name }}</div><div class="mt-1 text-xs text-slate-500">{{ str($document->status)->headline() }} @if($document->reviewed_at) · {{ $document->reviewed_at->format('M d, Y g:i A') }} @endif</div>@if($document->remarks)<div class="mt-1 text-sm text-rose-700">Remarks: {{ $document->remarks }}</div>@endif</div><div class="flex flex-wrap gap-2"><flux:button size="sm" variant="outline" href="{{ route('learner-documents.download', $document->id) }}">Download</flux:button>@if(auth()->user()->hasRole('registrar') && $document->status === 'pending')<flux:button size="sm" variant="primary" wire:click="$set('selectedDocumentApprovalId', {{ $document->id }})">Approve document</flux:button><flux:button size="sm" variant="danger" wire:click="$set('selectedDocumentRejectionId', {{ $document->id }})">Reject document</flux:button>@endif</div></div>
                    @empty
                        <div class="rounded-xl bg-slate-50 p-8 text-center text-sm text-slate-500">No documents have been uploaded by this learner.</div>
                    @endforelse
                </div></div>
                <div class="mt-6"><flux:heading size="sm">Document Review History</flux:heading><div class="mt-3 overflow-x-auto"><table class="w-full min-w-[600px] text-left text-sm"><thead class="border-b text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">Document</th><th class="px-3 py-2">Result</th><th class="px-3 py-2">Reviewed by</th><th class="px-3 py-2">Date</th><th class="px-3 py-2">Remarks</th></tr></thead><tbody class="divide-y">@forelse($this->reviewHistory as $history)<tr><td class="px-3 py-3">{{ str($history->document_type)->headline() }}<div class="text-xs text-slate-500">{{ $history->original_name }}</div></td><td class="px-3 py-3">{{ str($history->status)->headline() }}</td><td class="px-3 py-3">{{ $history->reviewer?->name ?? '—' }}</td><td class="px-3 py-3">{{ $history->reviewed_at?->format('M d, Y g:i A') }}</td><td class="px-3 py-3">{{ $history->remarks ?: '—' }}</td></tr>@empty<tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">No review history yet.</td></tr>@endforelse</tbody></table></div></div>
                <div class="mt-5 flex justify-end"><flux:button wire:click="closeReview">Close</flux:button></div>
            </div>
        </div>
    @endif

    {{-- Document approval confirmation modal --}}
    @if ($selectedDocumentApprovalId && ($documentToApprove = $this->reviewLearner?->learnerDocuments->firstWhere('id', $selectedDocumentApprovalId)))
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 p-3">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <flux:heading size="lg">Approve learner document</flux:heading>
                <flux:text class="mt-2">Mark {{ $documentToApprove->original_name }} as verified?</flux:text>
                <div class="mt-5 flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('selectedDocumentApprovalId', null)">Cancel</flux:button>
                    <flux:button variant="primary" wire:click="verifyDocument({{ $documentToApprove->id }})" wire:loading.attr="disabled">Confirm approval</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Document rejection modal --}}
    @if ($selectedDocumentRejectionId && ($documentToReject = $this->reviewLearner?->learnerDocuments->firstWhere('id', $selectedDocumentRejectionId)))
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 p-3">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <flux:heading size="lg">Return document for correction</flux:heading>
                <flux:text class="mt-2">Provide a reason for rejecting {{ $documentToReject->original_name }}.</flux:text>
                <div class="mt-4">
                    <flux:textarea wire:model="documentReviewRemarks" label="Remarks (required)" placeholder="Explain what needs to be corrected." />
                    @error('documentReviewRemarks') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('selectedDocumentRejectionId', null)">Cancel</flux:button>
                    <flux:button variant="danger" wire:click="rejectDocument({{ $documentToReject->id }})" wire:loading.attr="disabled">Confirm rejection</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Approve enrollment confirmation modal --}}
    @if ($selectedApprovalId && ($approval = $this->applications->firstWhere('id', $selectedApprovalId)))
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/60 p-3"><div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><flux:heading size="lg">Approve enrollment</flux:heading><flux:text class="mt-2">Confirm {{ $approval->learner_name }} for {{ $approval->course?->name }}? This will issue a Scholar ID and update the learner account role.</flux:text><div class="mt-5 flex justify-end gap-2"><flux:button variant="ghost" wire:click="$set('selectedApprovalId', null)">Cancel</flux:button><flux:button variant="primary" wire:click="confirm({{ $approval->id }})" wire:loading.attr="disabled">Confirm approval</flux:button></div></div></div>
    @endif

    {{-- Return for revision confirmation modal --}}
    @if ($selectedReturnId && ($returning = $this->applications->firstWhere('id', $selectedReturnId)))
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/60 p-3"><div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><flux:heading size="lg">Return application</flux:heading><flux:text class="mt-2">Return {{ $returning->learner_name }}'s application for revision?</flux:text><div class="mt-5 flex justify-end gap-2"><flux:button variant="ghost" wire:click="$set('selectedReturnId', null)">Cancel</flux:button><flux:button variant="primary" wire:click="returnForRevision({{ $returning->id }})">Return for revision</flux:button></div></div></div>
    @endif

    {{-- Delete confirmation modal --}}
    @if ($selectedDeleteId && ($deleting = $this->applications->firstWhere('id', $selectedDeleteId)))
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/60 p-3"><div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><flux:heading size="lg">Delete enrollment record</flux:heading><flux:text class="mt-2">Delete {{ $deleting->learner_name }}'s enrollment record? This action cannot be undone. Confirmed scholar records are protected.</flux:text><div class="mt-5 flex justify-end gap-2"><flux:button variant="ghost" wire:click="$set('selectedDeleteId', null)">Cancel</flux:button><flux:button variant="danger" wire:click="delete({{ $deleting->id }})">Delete record</flux:button></div></div></div>
    @endif
</div>
