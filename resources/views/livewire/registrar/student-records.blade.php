<?php

use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\LearnerProfile;
use App\Models\User;
use App\Models\TrainingBatch;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, mount, state, title, usesPagination};

usesPagination();

layout('layouts::app');
title('Student Records');
state([
    'search' => '', 'status' => request()->query('status', 'active'), 'institutionFilter' => '',
    'editingId' => null, 'editName' => '', 'editEmail' => '', 'editPhone' => '', 'editCourseId' => '', 'editBatchId' => '', 'editReason' => '',
    'archiveId' => null, 'archiveReason' => '', 'viewingId' => null,
]);
$institutionIds = computed(fn () => InstitutionScope::idsFor(auth()->user()));
$institutions = computed(fn () => \App\Models\Institution::query()->whereIn('id', $this->institutionIds)->orderBy('name')->get());
$courses = computed(fn () => Course::query()->whereIn('institution_id', $this->institutionIds)->orderBy('name')->get());
$batches = computed(fn () => TrainingBatch::query()->whereIn('institution_id', $this->institutionIds)->when(filled($this->editCourseId), fn ($q) => $q->where('course_id', (int) $this->editCourseId))->whereNotIn('status', ['completed','cancelled'])->orderBy('starts_at')->get());
$viewingRecord = computed(function () {
    if (! filled($this->viewingId)) return null;
    $query = EnrollmentApplication::query()->with(['institution', 'course', 'trainingBatch', 'learner']);
    $query->whereIn('institution_id', $this->institutionIds);
    if ($this->status === 'archived') $query->whereNotNull('archived_at'); else $query->whereNull('archived_at');
    return $query->find($this->viewingId);
});
$records = computed(function () {
    $query = EnrollmentApplication::query()->with(['institution','course','trainingBatch','learner'])
        ->whereIn('institution_id', $this->institutionIds);
    if ($this->status === 'archived') $query->whereNotNull('archived_at'); else $query->whereNull('archived_at');
    if (filled($this->institutionFilter)) $query->where('institution_id', (int) $this->institutionFilter);
    if (filled(trim($this->search))) {
        $needle = '%'.trim($this->search).'%';
        $query->where(fn ($q) => $q->where('learner_name','like',$needle)->orWhere('email','like',$needle)->orWhere('scholar_id','like',$needle)->orWhere('reference_no','like',$needle)->orWhereHas('course',fn($c)=>$c->where('name','like',$needle)));
    }
    return $query->orderByDesc('confirmed_at')->orderByDesc('submitted_at')->paginate(15);
});

$viewProfile = function (int $id): void {
    $query = EnrollmentApplication::query()->whereIn('institution_id', $this->institutionIds);
    if ($this->status === 'archived') $query->whereNotNull('archived_at'); else $query->whereNull('archived_at');
    $record = $query->findOrFail($id);
    $this->viewingId = $record->id;
};
$edit = function (int $id): void {
    $record = EnrollmentApplication::query()->whereIn('institution_id', $this->institutionIds)->whereNull('archived_at')->findOrFail($id);
    abort_if($record->application_status === 'confirmed' || filled($record->scholar_id), 422, 'Confirmed Scholar records are locked. Use the formal amendment process.');
    $this->editingId = $record->id; $this->editName = $record->learner_name; $this->editEmail = $record->email ?? '';
    $this->editPhone = $record->contact_number ?? ''; $this->editCourseId = (string) $record->course_id; $this->editBatchId = (string) ($record->training_batch_id ?? ''); $this->editReason = '';
};
$saveEdit = function (): void {
    $record = EnrollmentApplication::query()->whereIn('institution_id', $this->institutionIds)->whereNull('archived_at')->findOrFail($this->editingId);
    abort_if($record->application_status === 'confirmed' || filled($record->scholar_id), 422, 'Confirmed Scholar records are locked.');
    $data = $this->validate([
        'editName' => ['required','string','max:160'], 'editEmail' => ['nullable','email','max:255',Rule::unique('users','email')->ignore($record->learner_user_id)],
        'editPhone' => ['nullable','string','max:40'],
        'editCourseId' => ['required','integer',Rule::exists('courses','id')->where(fn($q)=>$q->where('institution_id',(int)$record->institution_id))],
        'editBatchId' => ['nullable','integer',Rule::exists('training_batches','id')->where(fn($q)=>$q->where('course_id',(int)$this->editCourseId)->where('institution_id',(int)$record->institution_id))],
        'editReason' => ['required','string','min:5','max:1000'],
    ]);
    $before = ActivityLogger::snapshot($record, ['learner_name','email','contact_number','course_id']);
    $record->update(['learner_name'=>$data['editName'],'email'=>$data['editEmail'] ?: null,'contact_number'=>$data['editPhone'] ?: null,'course_id'=>(int)$data['editCourseId'],'training_batch_id'=>filled($data['editBatchId']) ? (int)$data['editBatchId'] : null]);
    if ($record->learner_user_id) {
        User::query()->whereKey($record->learner_user_id)->update([
            'name' => $data['editName'],
            'email' => $data['editEmail'] ?: User::query()->whereKey($record->learner_user_id)->value('email'),
        ]);
        LearnerProfile::query()->where('user_id', $record->learner_user_id)->where('institution_id', $record->institution_id)->update([
            'contact_number' => $data['editPhone'] ?: null,
            'course_id' => (int) $data['editCourseId'],
        ]);
    }
    ActivityLogger::record('student_record_updated', 'Updated student record: '.$data['editReason'], (int)$record->institution_id, ['enrollment_id'=>$record->id,'reason'=>$data['editReason'],'changes'=>ActivityLogger::changes($record,$before)]);
    $this->reset('editingId','editName','editEmail','editPhone','editCourseId','editBatchId','editReason'); unset($this->records);
    Flux::toast(variant:'success',text:'Student record updated and reason recorded.');
};
$archive = function (int $id): void {
    $record = EnrollmentApplication::query()->whereIn('institution_id',$this->institutionIds)->whereNull('archived_at')->findOrFail($id);
    $this->archiveId = $record->id; $this->archiveReason = '';
};
$confirmArchive = function (): void {
    $data = $this->validate(['archiveReason'=>['required','string','min:5','max:1000']]);
    $record = EnrollmentApplication::query()->whereIn('institution_id',$this->institutionIds)->whereNull('archived_at')->findOrFail($this->archiveId);
    $record->forceFill(['archived_at'=>now(),'archived_by_user_id'=>auth()->id(),'archive_reason'=>$data['archiveReason']])->save();
    ActivityLogger::record('student_record_archived','Archived record: '.$data['archiveReason'],(int)$record->institution_id,['enrollment_id'=>$record->id,'reason'=>$data['archiveReason']]);
    $this->reset('archiveId','archiveReason'); unset($this->records); Flux::toast(variant:'success',text:'Record archived. It remains searchable under Archived Records.');
};
$restore = function (int $id): void {
    $record = EnrollmentApplication::query()->whereIn('institution_id',$this->institutionIds)->whereNotNull('archived_at')->findOrFail($id);
    $record->forceFill(['archived_at'=>null,'archived_by_user_id'=>null,'archive_reason'=>null])->save();
    ActivityLogger::record('student_record_restored','Restored archived enrollment record.',(int)$record->institution_id,['enrollment_id'=>$record->id]);
    unset($this->records); Flux::toast(variant:'success',text:'Record restored to the active list.');
};
?>

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-sm font-semibold text-blue-700">Student Records</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Master Student List</flux:heading><flux:text class="mt-1">Search and maintain records within your assigned institutions. Confirmed Scholar IDs are locked against direct edits.</flux:text></div><div class="flex gap-2"><flux:button variant="{{ $status === 'active' ? 'primary' : 'outline' }}" wire:click="$set('status','active')">Active records</flux:button><flux:button variant="{{ $status === 'archived' ? 'primary' : 'outline' }}" wire:click="$set('status','archived')">Archived records</flux:button></div></div>
    <flux:card class="bg-white p-5 shadow-sm"><div class="grid gap-3 md:grid-cols-[1fr_260px]"><flux:input wire:model.live.debounce.300ms="search" label="Search name, Scholar ID, reference, email, course" placeholder="Search records…"/><flux:select wire:model.live="institutionFilter" label="Institution"><flux:select.option value="">All assigned institutions</flux:select.option>@foreach($this->institutions as $institution)<flux:select.option value="{{ $institution->id }}">{{ $institution->code }} — {{ $institution->name }}</flux:select.option>@endforeach</flux:select></div></flux:card>
    @if($this->viewingRecord)<flux:card class="border-blue-200 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wide text-blue-700">Quick profile</p><flux:heading size="lg" class="mt-1">{{ $this->viewingRecord->learner_name }}</flux:heading><flux:text class="mt-1">{{ $this->viewingRecord->scholar_id ?: $this->viewingRecord->reference_no }} · {{ $this->viewingRecord->institution?->code }}</flux:text></div><flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('viewingId', null)">Close</flux:button></div><dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Email</dt><dd class="mt-1 break-words font-semibold">{{ $this->viewingRecord->email ?: 'Not recorded' }}</dd></div><div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Contact</dt><dd class="mt-1 font-semibold">{{ $this->viewingRecord->contact_number ?: 'Not recorded' }}</dd></div><div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Course</dt><dd class="mt-1 font-semibold">{{ $this->viewingRecord->course?->name ?: 'Not assigned' }}</dd></div><div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Training batch</dt><dd class="mt-1 font-semibold">{{ $this->viewingRecord->trainingBatch?->name ?: 'Not assigned' }}</dd></div><div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Application status</dt><dd class="mt-1 font-semibold">{{ str($this->viewingRecord->application_status)->headline() }}</dd></div><div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Dates</dt><dd class="mt-1 text-sm">Submitted: {{ $this->viewingRecord->submitted_at?->format('M j, Y') ?: '—' }}<br />Confirmed: {{ $this->viewingRecord->confirmed_at?->format('M j, Y') ?: '—' }}</dd></div></dl>@if($this->viewingRecord->archive_reason)<p class="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">Archive reason: {{ $this->viewingRecord->archive_reason }}</p>@endif</flux:card>@endif
    @if($editingId)<flux:card class="bg-white p-5 shadow-sm"><flux:heading size="lg">Update student details</flux:heading><form wire:submit="saveEdit" class="mt-4 grid gap-4 md:grid-cols-2"><flux:input wire:model="editName" label="Full name" required/><flux:input wire:model="editEmail" label="Email" type="email"/><flux:input wire:model="editPhone" label="Contact number"/><flux:select wire:model.live="editCourseId" label="Course" required>@foreach($this->courses as $course)<flux:select.option value="{{ $course->id }}">{{ $course->institution?->code }} · {{ $course->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="editBatchId" label="Training batch"><flux:select.option value="">No batch assigned</flux:select.option>@foreach($this->batches as $batch)<flux:select.option value="{{ $batch->id }}">{{ $batch->code }} — {{ $batch->name }}</flux:select.option>@endforeach</flux:select><div class="md:col-span-2"><flux:textarea wire:model="editReason" label="Reason for change (required for audit)" required/></div>@error('editReason')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror<div class="flex justify-end gap-2 md:col-span-2"><flux:button variant="ghost" type="button" wire:click="$set('editingId', null)">Cancel</flux:button><flux:button type="submit" variant="primary">Save changes</flux:button></div></form></flux:card>@endif
    @if($archiveId)<flux:card class="border-rose-200 bg-white p-5"><flux:heading size="lg">Archive student record</flux:heading><form wire:submit="confirmArchive" class="mt-3 space-y-3"><flux:textarea wire:model="archiveReason" label="Archive reason" placeholder="For example: withdrawn, inactive, duplicate, or retention review" required/>@error('archiveReason')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror<div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('archiveId', null)">Cancel</flux:button><flux:button type="submit" variant="danger">Confirm archive</flux:button></div></form></flux:card>@endif
    <flux:card class="bg-white p-0 shadow-sm"><div class="overflow-x-auto"><table class="w-full min-w-[1100px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Scholar / Reference</th><th class="px-4 py-3">Course / Batch</th><th class="px-4 py-3">Institution</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Contact</th><th class="px-4 py-3">Action</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse($this->records as $record)<tr wire:key="student-record-{{ $record->id }}"><td class="px-4 py-4"><p class="font-semibold">{{ $record->scholar_id ?: $record->learner_name }}</p><p class="text-xs text-slate-500">{{ $record->reference_no }}</p><p class="text-xs text-slate-500">{{ $record->email }}</p></td><td class="px-4 py-4"><p>{{ $record->course?->name ?? 'No course' }}</p><p class="text-xs text-slate-500">{{ $record->trainingBatch?->name ?? 'No batch assigned' }}</p></td><td class="px-4 py-4">{{ $record->institution?->code }}</td><td class="px-4 py-4"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold">{{ str($record->application_status)->headline() }}</span>@if($record->archived_at)<p class="mt-1 text-xs text-rose-600">Archived {{ $record->archived_at->format('M j, Y') }}</p>@endif</td><td class="px-4 py-4">{{ $record->contact_number ?: '—' }}</td><td class="px-4 py-4"><div class="flex flex-wrap gap-2"><flux:button size="sm" variant="ghost" wire:click="viewProfile({{ $record->id }})">View profile</flux:button>@if($status === 'archived')<flux:button size="sm" variant="primary" wire:click="restore({{ $record->id }})" wire:confirm="Restore this record?">Restore</flux:button>@else @if($record->application_status !== 'confirmed' && ! $record->scholar_id)<flux:button size="sm" variant="outline" wire:click="edit({{ $record->id }})">Update</flux:button>@else<span class="self-center text-xs text-slate-400">Confirmed record locked</span>@endif<flux:button size="sm" variant="ghost" wire:click="archive({{ $record->id }})">Archive</flux:button>@endif</div>@if($record->archive_reason)<p class="mt-1 max-w-xs text-xs text-slate-500">Reason: {{ $record->archive_reason }}</p>@endif</td></tr>@empty<tr><td colspan="6" class="px-4 py-12 text-center text-slate-500">No {{ $status === 'archived' ? 'archived ' : '' }}records match your search.</td></tr>@endforelse</tbody></table></div><div class="p-4">{{ $this->records->links() }}</div></flux:card>
</div>
