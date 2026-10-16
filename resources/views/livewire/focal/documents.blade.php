<?php

use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\OperationalDocument;
use App\Models\TrainingBatch;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use function Livewire\Volt\{computed, layout, mount, state, title, usesFileUploads};

layout('layouts::app');
title('Operational Documents');
usesFileUploads();

state([
    'institutionId' => '', 'courseId' => '', 'batchId' => '', 'enrollmentId' => '',
    'category' => 'attendance_sheet', 'titleText' => '', 'notes' => '', 'upload' => null,
    'filterCategory' => 'all', 'search' => '',
]);

mount(function (): void {
    abort_unless(auth()->user()?->account_status === 'active' && auth()->user()->hasRole('focal_person'), 403);
    $this->institutionId = (string) (InstitutionScope::idsFor(auth()->user())->first() ?? '');
});

$institutions = computed(fn () => Institution::query()->whereIn('id', InstitutionScope::idsFor(auth()->user()))->where('is_active', true)->orderBy('name')->get());
$courses = computed(fn () => Course::query()->where('institution_id', (int) $this->institutionId)->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()))->orderBy('name')->get());
$batches = computed(fn () => TrainingBatch::query()->with('course')->where('institution_id', (int) $this->institutionId)->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()))->orderBy('starts_at')->orderBy('name')->get());
$applications = computed(fn () => EnrollmentApplication::query()->where('institution_id', (int) $this->institutionId)->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()))->whereNull('archived_at')->orderBy('learner_name')->get(['id','institution_id','learner_name','scholar_id','reference_no','course_id','training_batch_id','application_status']));
$documents = computed(function () {
    $query = OperationalDocument::query()->with(['institution','course','trainingBatch','enrollmentApplication','uploader'])
        ->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()));
    if ($this->filterCategory !== 'all') $query->where('category', $this->filterCategory);
    $search = trim((string) $this->search);
    if ($search !== '') $query->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('original_name', 'like', '%'.$search.'%')->orWhere('notes', 'like', '%'.$search.'%'));
    return $query->latest()->limit(200)->get();
});

$updatedInstitutionId = function (): void {
    $this->reset('courseId', 'batchId', 'enrollmentId');
    unset($this->courses, $this->batches, $this->applications);
};

$uploadDocument = function (): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('focal_person'), 403);
    $institutionIds = InstitutionScope::idsFor($user);
    abort_unless($institutionIds->contains((int) $this->institutionId), 403);

    $data = $this->validate([
        'institutionId' => ['required','integer',Rule::exists('institutions','id')->where('is_active', true)],
        'courseId' => ['nullable','integer'], 'batchId' => ['nullable','integer'], 'enrollmentId' => ['nullable','integer'],
        'category' => ['required',Rule::in(['attendance_sheet','assessment_form','photo','other'])],
        'titleText' => ['required','string','max:180'], 'notes' => ['nullable','string','max:3000'],
        'upload' => ['required','file','max:10240','mimes:pdf,jpg,jpeg,png,webp'],
    ]);

    $institutionId = (int) $data['institutionId'];
    $course = null; $batch = null; $application = null;
    if (filled($data['courseId'] ?? null)) $course = Course::query()->whereKey((int) $data['courseId'])->where('institution_id',$institutionId)->firstOrFail();
    if (filled($data['batchId'] ?? null)) $batch = TrainingBatch::query()->whereKey((int) $data['batchId'])->where('institution_id',$institutionId)->firstOrFail();
    if (filled($data['enrollmentId'] ?? null)) $application = EnrollmentApplication::query()->whereKey((int) $data['enrollmentId'])->where('institution_id',$institutionId)->firstOrFail();
    if ($batch && $course && (int) $batch->course_id !== (int) $course->id) throw ValidationException::withMessages(['batchId' => 'The selected batch does not belong to the selected course.']);
    if ($application && $batch && (int) $application->training_batch_id !== (int) $batch->id) throw ValidationException::withMessages(['enrollmentId' => 'The selected trainee is not assigned to that batch.']);
    if ($application && $course && (int) $application->course_id !== (int) $course->id) throw ValidationException::withMessages(['enrollmentId' => 'The selected trainee is not enrolled in that course.']);

    $file = $data['upload'];
    $path = $file->store('operational-documents/'.$institutionId, 'local');
    $document = OperationalDocument::create([
        'institution_id' => $institutionId, 'uploaded_by_user_id' => $user->id,
        'course_id' => $course?->id, 'training_batch_id' => $batch?->id,
        'enrollment_application_id' => $application?->id, 'category' => $data['category'],
        'title' => trim($data['titleText']), 'original_name' => $file->getClientOriginalName(),
        'path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
        'size' => (int) $file->getSize(), 'notes' => $data['notes'] ?: null,
    ]);
    ActivityLogger::record('operational_document_uploaded', 'Uploaded operational document '.$document->title, $institutionId, ['document_id'=>$document->id,'category'=>$document->category,'size'=>$document->size]);
    $this->reset('titleText','notes','upload','courseId','batchId','enrollmentId');
    unset($this->documents);
    Flux::toast(variant: 'success', text: 'Document uploaded to the private institutional library.');
};

$deleteDocument = function (int $id): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('focal_person'), 403);
    $document = OperationalDocument::query()->whereIn('institution_id', InstitutionScope::idsFor($user))->findOrFail($id);
    abort_unless((int) $document->uploaded_by_user_id === (int) $user->id, 403, 'Only the original uploader may remove this document.');
    Storage::disk('local')->delete($document->path);
    $document->delete();
    ActivityLogger::record('operational_document_deleted', 'Removed operational document '.$document->title, (int) $document->institution_id, ['document_id'=>$id]);
    unset($this->documents);
    Flux::toast(variant: 'success', text: 'Document removed.');
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <header><p class="text-sm font-semibold text-cyan-700">Institution-private file library</p><h1 class="mt-1 text-3xl font-black text-slate-900">Document & Admin Tasks</h1><p class="mt-2 max-w-3xl text-sm text-slate-600">Upload and retrieve attendance sheets, assessment forms, and supporting photos by institution, course, batch, or scholar. Downloads are authorization-checked and stored outside the public web directory.</p></header>
    <flux:card class="bg-white p-5 shadow-sm sm:p-6">
        <flux:heading size="lg">Upload operational document</flux:heading>
        <form id="operational-document-form" data-user-id="{{ auth()->id() }}" data-offline-upload-url="{{ route('focal.documents.offline-upload') }}" wire:submit="uploadDocument" class="mt-5 grid gap-4 md:grid-cols-2">
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Institution</label><select id="doc-institution" wire:model.live="institutionId" required class="w-full rounded-lg border-slate-300 text-sm"><option value="">Select</option>@foreach($this->institutions as $institution)<option value="{{ $institution->id }}">{{ $institution->code }} — {{ $institution->name }}</option>@endforeach</select>@error('institutionId')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Category</label><select id="doc-category" wire:model="category" required class="w-full rounded-lg border-slate-300 text-sm"><option value="attendance_sheet">Attendance sheet</option><option value="assessment_form">Assessment form</option><option value="photo">Photo / image</option><option value="other">Other supporting document</option></select></div>
            <flux:input id="doc-title" wire:model="titleText" label="Document title" required placeholder="e.g. OAP Batch 1 attendance — Oct 16" />
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Course (optional)</label><select id="doc-course" wire:model="courseId" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Not linked</option>@foreach($this->courses as $course)<option value="{{ $course->id }}">{{ $course->code }} — {{ $course->name }}</option>@endforeach</select></div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Batch (optional)</label><select id="doc-batch" wire:model="batchId" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Not linked</option>@foreach($this->batches as $batch)<option value="{{ $batch->id }}">{{ $batch->code }} — {{ $batch->name }}</option>@endforeach</select>@error('batchId')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Scholar (optional)</label><select id="doc-enrollment" wire:model="enrollmentId" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Not linked</option>@foreach($this->applications as $application)<option value="{{ $application->id }}">{{ $application->scholar_id ?: $application->reference_no }} — {{ $application->learner_name }}</option>@endforeach</select>@error('enrollmentId')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
            <div class="md:col-span-2"><flux:input id="doc-file" wire:model="upload" type="file" label="Select file (PDF or image, max 10 MB)" accept=".pdf,.jpg,.jpeg,.png,.webp" required />@error('upload')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
            <div class="md:col-span-2"><flux:textarea id="doc-notes" wire:model="notes" label="Notes (optional)" rows="2" /></div>
            <div class="flex flex-col justify-between gap-3 md:col-span-2 sm:flex-row sm:items-center"><p id="offline-upload-status" class="text-xs text-slate-500" aria-live="polite">Offline uploads are queued on this device and will sync when you are back online and signed in as the same account.</p><flux:button type="submit" variant="primary" icon="arrow-up-tray">Upload privately</flux:button></div>
        </form>
    </flux:card>
    <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 sm:flex-row"><div class="flex-1"><flux:input wire:model.live.debounce.300ms="search" placeholder="Search title, filename, or notes" icon="magnifying-glass" /></div><select wire:model.live="filterCategory" class="rounded-lg border-slate-300 text-sm"><option value="all">All categories</option><option value="attendance_sheet">Attendance sheets</option><option value="assessment_form">Assessment forms</option><option value="photo">Photos</option><option value="other">Other</option></select></div>
        <div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-3 py-3">Document</th><th class="px-3 py-3">Category / Institution</th><th class="px-3 py-3">Related record</th><th class="px-3 py-3">Uploaded</th><th class="px-3 py-3">Action</th></tr></thead><tbody class="divide-y divide-slate-100">
        @forelse($this->documents as $document)<tr wire:key="operational-document-{{ $document->id }}"><td class="px-3 py-4"><p class="font-semibold text-slate-900">{{ $document->title }}</p><p class="text-xs text-slate-500">{{ $document->original_name }} · {{ number_format($document->size / 1024, 1) }} KB</p>@if($document->notes)<p class="mt-1 text-xs text-slate-600">{{ $document->notes }}</p>@endif</td><td class="px-3 py-4">{{ str($document->category)->replace('_',' ')->headline() }}<p class="text-xs text-slate-500">{{ $document->institution?->code }}</p></td><td class="px-3 py-4">{{ $document->course?->code ?: '—' }}<p class="text-xs text-slate-500">{{ $document->trainingBatch?->name ?: $document->enrollmentApplication?->learner_name ?: 'No link' }}</p></td><td class="px-3 py-4">{{ $document->created_at?->format('M j, Y') }}<p class="text-xs text-slate-500">{{ $document->uploader?->name ?: 'Former user' }}</p></td><td class="px-3 py-4"><div class="flex gap-2"><flux:button size="sm" variant="outline" :href="route('focal.documents.download', $document)" icon="arrow-down-tray">Download</flux:button>@if((int)$document->uploaded_by_user_id === (int)auth()->id())<flux:button size="sm" variant="ghost" wire:click="deleteDocument({{ $document->id }})" wire:confirm="Remove this uploaded document?">Remove</flux:button>@endif</div></td></tr>@empty<tr><td colspan="5" class="px-3 py-10 text-center text-slate-500">No operational documents have been uploaded to the assigned institutions.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>

<script src="{{ asset('offline-operational-documents.js') }}" data-navigate-once></script>
