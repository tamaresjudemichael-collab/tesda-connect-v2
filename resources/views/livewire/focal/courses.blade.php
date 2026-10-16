<?php

use App\Models\ActivityLog;
use App\Models\Course;
use App\Models\Institution;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, mount, state, title};

layout('layouts::app');
title('Focal Person Course Management');

state([
    'formOpen' => false, 'editingId' => null, 'institutionId' => '', 'code' => '', 'name' => '',
    'qualificationLevel' => '', 'trainingHours' => '', 'description' => '', 'requirements' => '',
    'curriculumReference' => '', 'isActive' => '1', 'search' => '', 'statusFilter' => 'active', 'historyCourseId' => null,
]);

mount(function (): void {
    abort_unless(auth()->user()?->account_status === 'active' && auth()->user()->hasRole('focal_person'), 403);
    $this->institutionId = (string) (InstitutionScope::idsFor(auth()->user())->first() ?? '');
});

$institutions = computed(fn () => Institution::query()->where('is_active', true)->whereIn('id', InstitutionScope::idsFor(auth()->user()))->orderBy('name')->get());

$courses = computed(function () {
    $query = Course::query()->with('institution')->withCount('batches')
        ->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()));
    if ($this->statusFilter === 'active') $query->where('is_active', true);
    elseif ($this->statusFilter === 'archived') $query->where('is_active', false);
    $search = trim((string) $this->search);
    if ($search !== '') $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'));
    return $query->with(['batches' => fn ($batches) => $batches
        ->whereNotIn('status', ['completed', 'cancelled'])
        ->withCount(['applications as confirmed_trainees_count' => fn ($applications) => $applications->where('application_status', 'confirmed')])
        ->orderBy('starts_at')])
        ->orderBy('institution_id')->orderBy('name')->get();
});

$courseHistory = computed(function () {
    if (! $this->historyCourseId) return collect();
    $course = Course::query()->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()))->findOrFail((int) $this->historyCourseId);
    return ActivityLog::query()->with('user')
        ->where('institution_id', $course->institution_id)
        ->whereIn('event', ['course_created', 'course_updated', 'course_activated', 'course_archived'])
        ->where('metadata->course_id', $course->id)
        ->latest()->take(20)->get();
});

$resetForm = function (): void {
    $this->reset('editingId', 'code', 'name', 'qualificationLevel', 'trainingHours', 'description', 'requirements', 'curriculumReference');
    $this->institutionId = (string) ($this->institutions->first()?->id ?? '');
    $this->isActive = '1';
    $this->resetValidation();
};

$openCreate = function (): void { $this->resetForm(); $this->formOpen = true; };

$edit = function (int $id): void {
    $course = Course::query()->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()))->findOrFail($id);
    $this->resetValidation();
    $this->editingId = $course->id;
    $this->institutionId = (string) $course->institution_id;
    $this->code = $course->code;
    $this->name = $course->name;
    $this->qualificationLevel = $course->qualification_level ?? '';
    $this->trainingHours = $course->training_hours !== null ? (string) $course->training_hours : '';
    $this->description = $course->description ?? '';
    $this->requirements = $course->requirements ?? '';
    $this->curriculumReference = $course->curriculum_reference ?? '';
    $this->isActive = $course->is_active ? '1' : '0';
    $this->formOpen = true;
};

$save = function (): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('focal_person'), 403);
    abort_unless(InstitutionScope::idsFor($user)->contains((int) $this->institutionId), 403);
    $this->code = strtoupper(trim($this->code));
    $validated = $this->validate([
        'institutionId' => ['required', 'integer', Rule::exists('institutions', 'id')->where('is_active', true)],
        'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]{2,40}$/', Rule::unique('courses', 'code')->where('institution_id', $this->institutionId)->ignore($this->editingId)],
        'name' => ['required', 'string', 'max:255'],
        'qualificationLevel' => ['nullable', 'string', 'max:30'],
        'trainingHours' => ['nullable', 'integer', 'min:1', 'max:65535'],
        'description' => ['nullable', 'string', 'max:5000'],
        'requirements' => ['nullable', 'string', 'max:5000'],
        'curriculumReference' => ['nullable', 'string', 'max:500'],
        'isActive' => ['required', Rule::in(['1', '0'])],
    ]);
    $data = [
        'institution_id' => (int) $validated['institutionId'], 'code' => $validated['code'],
        'name' => trim($validated['name']), 'qualification_level' => $validated['qualificationLevel'] ?: null,
        'training_hours' => $validated['trainingHours'] ?: null, 'description' => $validated['description'] ?: null,
        'requirements' => $validated['requirements'] ?: null, 'curriculum_reference' => $validated['curriculumReference'] ?: null,
        'is_active' => $validated['isActive'] === '1',
    ];
    $editing = filled($this->editingId);
    if ($editing) {
        $course = Course::query()->whereIn('institution_id', InstitutionScope::idsFor($user))->findOrFail($this->editingId);
        $before = ActivityLogger::snapshot($course, ['institution_id','code','name','qualification_level','training_hours','description','requirements','curriculum_reference','is_active']);
        $course->update($data);
        $changes = ActivityLogger::changes($course, $before);
    } else {
        $course = Course::create($data);
        $changes = [];
    }
    ActivityLogger::record($editing ? 'course_updated' : 'course_created', ($editing ? 'Updated' : 'Created').' course '.$course->name, (int) $course->institution_id, ['course_id' => $course->id, 'changes' => $changes]);
    unset($this->courses);
    $this->resetForm(); $this->formOpen = false;
    Flux::toast(variant: 'success', text: $editing ? 'Course details saved.' : 'Course registered.');
};

$toggleStatus = function (int $id): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('focal_person'), 403);
    $course = Course::query()->whereIn('institution_id', InstitutionScope::idsFor($user))->findOrFail($id);
    $before = ActivityLogger::snapshot($course, ['is_active']);
    $course->update(['is_active' => ! $course->is_active]);
    ActivityLogger::record($course->is_active ? 'course_activated' : 'course_archived', ($course->is_active ? 'Reactivated' : 'Archived').' course '.$course->name, (int) $course->institution_id, ['course_id' => $course->id, 'changes' => ActivityLogger::changes($course, $before)]);
    unset($this->courses);
    Flux::toast(variant: 'success', text: $course->is_active ? 'Course reactivated.' : 'Course archived.');
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="text-sm font-semibold text-cyan-700">Assigned institutions only</p><h1 class="mt-1 text-3xl font-black text-slate-900">Course Management</h1><p class="mt-2 max-w-3xl text-sm text-slate-600">Register and update courses, maintain curriculum references, and archive offerings without deleting existing records.</p></div>
        <flux:button variant="primary" icon="plus" wire:click="openCreate">Register New Course</flux:button>
    </header>
    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="bg-white p-5"><flux:text>Visible Courses</flux:text><flux:heading size="xl">{{ $this->courses->count() }}</flux:heading></flux:card>
        <flux:card class="bg-white p-5"><flux:text>Active Courses</flux:text><flux:heading size="xl">{{ $this->courses->where('is_active', true)->count() }}</flux:heading></flux:card>
        <flux:card class="bg-white p-5"><flux:text>Assigned Institutions</flux:text><flux:heading size="xl">{{ $this->institutions->count() }}</flux:heading></flux:card>
    </div>
    @if ($formOpen)
        <flux:card class="bg-white p-5 shadow-sm sm:p-6">
            <div class="flex items-center justify-between gap-3"><flux:heading size="lg">{{ $editingId ? 'Update course details' : 'Register new course' }}</flux:heading><flux:button variant="ghost" icon="x-mark" wire:click="$set('formOpen', false)" aria-label="Close" /></div>
            <form wire:submit="save" class="mt-5 grid gap-4 md:grid-cols-2">
                <div><label class="mb-1 block text-sm font-medium text-slate-700">Assigned institution</label><select wire:model="institutionId" required class="w-full rounded-lg border-slate-300 text-sm"><option value="">Select institution</option>@foreach($this->institutions as $institution)<option value="{{ $institution->id }}">{{ $institution->code }} — {{ $institution->name }}</option>@endforeach</select>@error('institutionId')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
                <flux:input wire:model="code" label="Course code" required placeholder="OAP-NC2" />
                <flux:input wire:model="name" label="Course title" required />
                <flux:input wire:model="qualificationLevel" label="Qualification level" placeholder="NC II" />
                <flux:input wire:model="trainingHours" type="number" min="1" label="Duration (training hours)" />
                <flux:input wire:model="curriculumReference" label="TESDA Curriculum reference" placeholder="Reference number or URL" />
                <div class="md:col-span-2"><flux:textarea wire:model="description" label="Description" rows="2" /></div>
                        <div class="md:col-span-2"><flux:textarea wire:model="requirements" label="Requirements" rows="2" /></div>
                <div><label class="mb-1 block text-sm font-medium text-slate-700">Status</label><select wire:model="isActive" class="w-full rounded-lg border-slate-300 text-sm"><option value="1">Active</option><option value="0">Archived</option></select></div>
                <div class="flex items-end justify-end gap-2"><flux:button variant="ghost" type="button" wire:click="$set('formOpen', false)">Cancel</flux:button><flux:button variant="primary" type="submit">Save Course</flux:button></div>
            </form>
        </flux:card>
    @endif
    <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 sm:flex-row"><div class="flex-1"><flux:input wire:model.live.debounce.300ms="search" placeholder="Search course code or title" icon="magnifying-glass" /></div><select wire:model.live="statusFilter" class="rounded-lg border-slate-300 text-sm"><option value="active">Active courses</option><option value="archived">Archived courses</option><option value="all">All courses</option></select></div>
        <div class="overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-3 py-3">Institution</th><th class="px-3 py-3">Course</th><th class="px-3 py-3">Qualification / Hours</th><th class="px-3 py-3">Enrollment / Slots</th><th class="px-3 py-3">Schedule</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Actions</th></tr></thead><tbody class="divide-y divide-slate-100">
        @forelse($this->courses as $course)<tr wire:key="focal-course-{{ $course->id }}"><td class="px-3 py-4">{{ $course->institution?->code }}<p class="text-xs text-slate-500">{{ $course->institution?->name }}</p></td><td class="px-3 py-4"><p class="font-bold">{{ $course->name }}</p><p class="text-xs text-slate-500">{{ $course->code }}</p>@if($course->curriculum_reference)<p class="mt-1 text-xs text-cyan-700">Curriculum: {{ $course->curriculum_reference }}</p>@endif</td><td class="px-3 py-4">{{ $course->qualification_level ?: '—' }}<p class="text-xs text-slate-500">{{ $course->training_hours ? $course->training_hours.' hours' : 'Hours not set' }}</p></td><td class="px-3 py-4"><p class="font-semibold">{{ $course->batches->sum('confirmed_trainees_count') }} enrolled</p><p class="text-xs text-slate-500">{{ $course->batches->sum(fn ($batch) => max(0, (int) $batch->capacity - (int) $batch->confirmed_trainees_count)) }} slots remaining · {{ $course->batches->count() }} current batch(es)</p></td><td class="px-3 py-4">{{ $course->batches->whereNotNull('starts_at')->min('starts_at')?->format('M j, Y') ?? '—' }}<p class="text-xs text-slate-500">to {{ $course->batches->whereNotNull('ends_at')->max('ends_at')?->format('M j, Y') ?? '—' }}</p></td><td class="px-3 py-4"><span class="rounded-full px-2 py-1 text-xs font-bold {{ $course->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $course->is_active ? 'Active' : 'Archived' }}</span></td><td class="px-3 py-4"><div class="flex flex-wrap gap-2"><flux:button size="sm" variant="outline" wire:click="edit({{ $course->id }})">Edit</flux:button><flux:button size="sm" variant="ghost" wire:click="$set('historyCourseId', {{ $course->id }})">History</flux:button><flux:button size="sm" variant="ghost" wire:click="toggleStatus({{ $course->id }})" wire:confirm="{{ $course->is_active ? 'Archive this course? Existing records will be retained.' : 'Reactivate this course?' }}">{{ $course->is_active ? 'Archive' : 'Reactivate' }}</flux:button></div></td></tr>@empty<tr><td colspan="7" class="px-3 py-10 text-center text-slate-500">No matching courses exist for your assigned institutions.</td></tr>@endforelse
        </tbody></table></div>
        <p class="text-xs text-slate-500">Course changes are recorded in the system activity log. Archiving hides a course from new active selections; existing training and learner history are retained.</p>
    </section>
    @if ($historyCourseId)
        @php($historyCourse = $this->courses->firstWhere('id', (int) $historyCourseId))
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3"><div><flux:heading size="lg">Course Change History</flux:heading><flux:text class="mt-1">{{ $historyCourse?->name ?? 'Selected course' }} · latest 20 logged changes</flux:text></div><flux:button variant="ghost" icon="x-mark" wire:click="$set('historyCourseId', null)" aria-label="Close history" /></div>
            <div class="mt-4 divide-y divide-slate-100">@forelse($this->courseHistory as $entry)<div class="py-3"><p class="font-semibold text-slate-900">{{ $entry->description }}</p><p class="text-xs text-slate-500">{{ $entry->user?->name ?? 'Former user' }} · {{ $entry->created_at?->format('M j, Y g:i A') }}</p>@if(!empty($entry->metadata['changes']))<pre class="mt-2 overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ json_encode($entry->metadata['changes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif</div>@empty<div class="py-8 text-center text-sm text-slate-500">No changes logged for this course yet.</div>@endforelse</div>
        </section>
    @endif
</div>
