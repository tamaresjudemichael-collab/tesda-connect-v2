<?php

use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\TrainingBatch;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Enrollment Monitoring');

state([
    'status' => 'all',
    'formOpen' => false,
    'editingId' => null,
    'institution_id' => null,
    'course_id' => null,
    'training_batch_id' => null,
    'learner_name' => '',
    'email' => '',
    'contact_number' => '',
    'application_status' => 'draft',
    'record_completeness' => 0,
    'remarks' => '',
]);

$institutionIds = computed(fn () => auth()->user()->institutions()->pluck('institutions.id'));
$institutions = computed(fn () => Institution::query()->whereIn('id', $this->institutionIds)->where('is_active', true)->orderBy('name')->get());
$courses = computed(fn () => Course::query()->where('institution_id', $this->institution_id)->where('is_active', true)->orderBy('name')->get());
$batches = computed(fn () => TrainingBatch::query()->where('institution_id', $this->institution_id)->where('course_id', $this->course_id)->orderBy('starts_at')->get());

$applications = computed(function () {
    return EnrollmentApplication::query()
        ->with(['course', 'trainingBatch'])
        ->whereIn('institution_id', $this->institutionIds)
        ->when($this->status !== 'all', fn ($query) => $query->where('application_status', $this->status))
        ->latest('submitted_at')
        ->get();
});

$resetForm = function (): void {
    $this->reset('editingId', 'institution_id', 'course_id', 'training_batch_id', 'learner_name', 'email', 'contact_number', 'application_status', 'record_completeness', 'remarks');
    $this->application_status = 'draft';
    $this->record_completeness = 0;
    $this->resetValidation();
};

$openCreate = function (): void {
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
    $this->application_status = $application->application_status;
    $this->record_completeness = $application->record_completeness;
    $this->remarks = $application->remarks ?? '';
    unset($this->courses, $this->batches);
    $this->formOpen = true;
};

$save = function (): void {
    $validated = $this->validate([
        'institution_id' => ['required', Rule::in($this->institutionIds->all())],
        'course_id' => ['required', Rule::exists('courses', 'id')->where(fn ($query) => $query->where('institution_id', $this->institution_id)->where('is_active', true))],
        'training_batch_id' => ['nullable', Rule::exists('training_batches', 'id')->where(fn ($query) => $query->where('institution_id', $this->institution_id)->where('course_id', $this->course_id))],
        'learner_name' => ['required', 'string', 'max:255'],
        'email' => ['nullable', 'email', 'max:255'],
        'contact_number' => ['nullable', 'string', 'max:30'],
        'application_status' => ['required', Rule::in(['draft', 'submitted', 'under_review', 'returned_for_revision', 'verified', 'confirmed'])],
        'record_completeness' => ['required', 'integer', 'min:0', 'max:100'],
        'remarks' => ['nullable', 'string', 'max:2000'],
    ]);

    if ($this->editingId) {
        $application = EnrollmentApplication::findOrFail($this->editingId);
        abort_unless($this->institutionIds->contains($application->institution_id), 403);
        $application->update($validated);
    } else {
        EnrollmentApplication::create([
            ...$validated,
            'reference_no' => 'TC-'.now()->format('Y').'-'.str_pad((string) ((EnrollmentApplication::max('id') ?? 0) + 1), 4, '0', STR_PAD_LEFT),
            'submitted_at' => in_array($validated['application_status'], ['submitted', 'under_review', 'returned_for_revision', 'verified', 'confirmed']) ? now() : null,
        ]);
    }

    unset($this->applications);
    $this->resetForm();
    $this->formOpen = false;
    Flux::toast(variant: 'success', text: 'Enrollment record saved.');
};

$verify = function (int $id): void {
    $application = EnrollmentApplication::findOrFail($id);
    abort_unless($this->institutionIds->contains($application->institution_id), 403);
    $application->update(['application_status' => 'verified', 'verified_at' => now(), 'record_completeness' => 100]);
    unset($this->applications);
    Flux::toast(variant: 'success', text: 'Application verified.');
};

$returnForRevision = function (int $id): void {
    $application = EnrollmentApplication::findOrFail($id);
    abort_unless($this->institutionIds->contains($application->institution_id), 403);
    $application->update(['application_status' => 'returned_for_revision', 'remarks' => 'Please review and complete the missing registration requirements.']);
    unset($this->applications);
    Flux::toast(variant: 'warning', text: 'Application returned for revision.');
};

$delete = function (int $id): void {
    $application = EnrollmentApplication::findOrFail($id);
    abort_unless($this->institutionIds->contains($application->institution_id), 403);
    $application->delete();
    unset($this->applications);
    Flux::toast(variant: 'success', text: 'Enrollment record deleted.');
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-semibold text-cyan-600">Registrar workspace</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Enrollment Monitoring</flux:heading><flux:text class="mt-1">Review digital MIS 03-01 submissions and advance the learner intake flow.</flux:text></div><flux:button variant="primary" icon="user-plus" wire:click="openCreate">New learner record</flux:button></div>

    @if ($formOpen)
        <flux:card class="bg-white p-6 shadow-sm"><div class="flex items-start justify-between gap-4"><div><flux:heading size="lg">{{ $editingId ? 'Edit learner record' : 'New learner record' }}</flux:heading><flux:text class="mt-1">This creates a staff-entered intake record. Learners can later complete their full MIS 03-01 profile.</flux:text></div><flux:button variant="ghost" icon="x-mark" wire:click="$set('formOpen', false)" /></div><form wire:submit="save" class="mt-6 grid gap-5 md:grid-cols-2 lg:grid-cols-3"><flux:select wire:model.live="institution_id" label="Institution" required><flux:select.option value="">Select institution</flux:select.option>@foreach($this->institutions as $institution)<flux:select.option value="{{ $institution->id }}">{{ $institution->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model.live="course_id" label="Course" required><flux:select.option value="">Select course</flux:select.option>@foreach($this->courses as $course)<flux:select.option value="{{ $course->id }}">{{ $course->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="training_batch_id" label="Batch"><flux:select.option value="">Not assigned yet</flux:select.option>@foreach($this->batches as $batch)<flux:select.option value="{{ $batch->id }}">{{ $batch->name }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="learner_name" label="Learner name" required /><flux:input wire:model="email" type="email" label="Email address" /><flux:input wire:model="contact_number" label="Contact number" /><flux:select wire:model="application_status" label="Application status" required>@foreach(['draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'returned_for_revision' => 'Returned for revision', 'verified' => 'Verified', 'confirmed' => 'Confirmed'] as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="record_completeness" type="number" min="0" max="100" label="Record completeness (%)" required /><flux:textarea wire:model="remarks" label="Remarks" class="lg:col-span-3" /><div class="flex justify-end gap-3 lg:col-span-3"><flux:button variant="ghost" type="button" wire:click="resetForm">Reset</flux:button><flux:button variant="primary" type="submit">Save record</flux:button></div></form></flux:card>
    @endif

    <flux:card class="bg-white p-5 shadow-sm"><div class="flex flex-wrap items-center gap-2"><span class="mr-2 text-sm font-semibold text-slate-600">Filter:</span>@foreach (['all' => 'All records', 'under_review' => 'Under review', 'returned_for_revision' => 'For revision', 'verified' => 'Verified', 'confirmed' => 'Confirmed'] as $value => $label)<button wire:click="$set('status', '{{ $value }}')" class="rounded-full px-3 py-1.5 text-sm font-medium {{ $status === $value ? 'bg-[#102f68] text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">{{ $label }}</button>@endforeach</div><div class="mt-5 overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm"><thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400"><tr><th class="px-3 py-3">Learner</th><th class="px-3 py-3">Course / Batch</th><th class="px-3 py-3">Completeness</th><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse($this->applications as $application)<tr><td class="px-3 py-4"><p class="font-semibold text-slate-800">{{ $application->learner_name }}</p><p class="mt-0.5 text-xs text-slate-400">{{ $application->reference_no }} · {{ $application->email }}</p></td><td class="px-3 py-4 text-slate-600">{{ $application->course?->name ?? 'Course pending' }}<p class="mt-0.5 text-xs text-slate-400">{{ $application->trainingBatch?->name ?? 'Batch not assigned' }}</p></td><td class="px-3 py-4"><div class="flex items-center gap-2"><div class="h-2 w-20 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $application->record_completeness === 100 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $application->record_completeness }}%"></div></div><span class="text-xs font-bold text-slate-600">{{ $application->record_completeness }}%</span></div></td><td class="px-3 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ in_array($application->application_status, ['verified','confirmed']) ? 'bg-emerald-50 text-emerald-700' : ($application->application_status === 'returned_for_revision' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">{{ Str::headline($application->application_status) }}</span></td><td class="px-3 py-4 text-right"><div class="flex justify-end gap-2">@if(in_array($application->application_status, ['under_review', 'submitted']))<flux:button size="sm" variant="primary" wire:click="verify({{ $application->id }})">Verify</flux:button><flux:button size="sm" variant="ghost" wire:click="returnForRevision({{ $application->id }})">Return</flux:button>@endif<flux:button size="sm" variant="filled" wire:click="edit({{ $application->id }})">Edit</flux:button><flux:button size="sm" variant="danger" wire:click="delete({{ $application->id }})" wire:confirm="Delete {{ $application->learner_name }}'s record? This cannot be undone.">Delete</flux:button></div></td></tr>@empty<tr><td colspan="5" class="px-3 py-12 text-center text-slate-400">No enrollment records match this filter.</td></tr>@endforelse</tbody></table></div></flux:card>
</div>
