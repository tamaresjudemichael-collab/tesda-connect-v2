<?php

use App\Models\Announcement;
use App\Models\AnnouncementView;
use App\Models\Institution;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use function Livewire\Volt\{computed, layout, mount, state, title};

layout('layouts::app');
title('Announcements');

state([
    'formOpen' => false,
    'editingId' => null,
    'institution_id' => '',
    'title' => '',
    'body' => '',
    'audience' => 'scholar',
    'published_at' => '',
    'expires_at' => '',
    'deadline_at' => '',
    'filterStatus' => 'all',
]);

mount(function (): void {
    $user = auth()->user();
    if (! $user || ! $user->hasRole('focal_person')) {
        return;
    }

    $institutionIds = InstitutionScope::idsFor($user);
    $visibleIds = Announcement::query()
        ->whereNotNull('published_at')
        ->where('published_at', '<=', now())
        ->whereNull('archived_at')
        ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
        ->whereIn('audience', ['all', 'focal_person'])
        ->where(function ($query) use ($institutionIds): void {
            $query->whereNull('institution_id');
            if ($institutionIds->isNotEmpty()) {
                $query->orWhereIn('institution_id', $institutionIds);
            }
        })
        ->pluck('id');

    foreach ($visibleIds as $announcementId) {
        AnnouncementView::query()->firstOrCreate(
            ['announcement_id' => $announcementId, 'user_id' => $user->id],
            ['viewed_at' => now()]
        );
    }
});

$canManage = computed(fn () => auth()->user()?->hasRole('administrator', 'registrar') ?? false);

$institutions = computed(function () {
    $user = auth()->user();

    return Institution::query()
        ->where('is_active', true)
        ->whereIn('id', InstitutionScope::idsFor($user))
        ->orderBy('name')
        ->get();
});

$announcements = computed(function () {
    $user = auth()->user();
    $institutionIds = InstitutionScope::idsFor($user);

    $query = Announcement::query()
        ->with(['institution', 'author'])
        ->withCount('views')
        ->where(function ($scope) use ($institutionIds): void {
            $scope->whereNull('institution_id');
            if ($institutionIds->isNotEmpty()) {
                $scope->orWhereIn('institution_id', $institutionIds);
            }
        });

    if ($user->hasRole('administrator', 'registrar')) {
        $query->with([
            'views' => fn ($views) => $views->latest('viewed_at')->limit(5),
            'views.user:id,name',
        ]);
    }

    if ($user->hasRole('focal_person')) {
        $query->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereIn('audience', ['all', 'focal_person']);
    }

    switch ($this->filterStatus) {
        case 'draft':
            $query->whereNull('published_at')->whereNull('archived_at');
            break;
        case 'scheduled':
            $query->whereNotNull('published_at')->where('published_at', '>', now())->whereNull('archived_at');
            break;
        case 'published':
            $query->whereNotNull('published_at')->where('published_at', '<=', now())
                ->whereNull('archived_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
            break;
        case 'expired':
            $query->whereNotNull('expires_at')->where('expires_at', '<=', now())->whereNull('archived_at');
            break;
        case 'archived':
            $query->whereNotNull('archived_at');
            break;
    }

    return $query->latest('created_at')->get();
});

$requireManager = function (): void {
    abort_unless(auth()->user()?->hasRole('administrator', 'registrar'), 403);
};

$findManagedAnnouncement = function (int $id): Announcement {
    abort_unless(auth()->user()?->hasRole('administrator', 'registrar'), 403);
    $user = auth()->user();
    $announcement = Announcement::query()->findOrFail($id);

    if (! $user->hasRole('administrator')) {
        abort_unless(
            $announcement->institution_id !== null
                && InstitutionScope::idsFor($user)->contains((int) $announcement->institution_id),
            403,
            'You may only manage announcements for your assigned institution.'
        );
    }

    return $announcement;
};

$resetForm = function (): void {
    $this->reset('editingId', 'institution_id', 'title', 'body', 'audience', 'published_at', 'expires_at', 'deadline_at');
    $this->audience = 'scholar';

    if (! auth()->user()->hasRole('administrator')) {
        $this->institution_id = (string) ($this->institutions->first()?->id ?? '');
    }

    $this->resetValidation();
};

$openCreate = function (): void {
    $this->requireManager();
    $this->resetForm();
    $this->formOpen = true;
};

$edit = function (int $id): void {
    $this->requireManager();
    $announcement = $this->findManagedAnnouncement($id);
    abort_if($announcement->archived_at !== null, 403, 'Restore an archived announcement before editing it.');

    $this->resetValidation();
    $this->editingId = $announcement->id;
    $this->institution_id = $announcement->institution_id === null ? '' : (string) $announcement->institution_id;
    $this->title = $announcement->title;
    $this->body = $announcement->body;
    $this->audience = $announcement->audience;
    $this->published_at = $announcement->published_at?->format('Y-m-d\TH:i') ?? '';
    $this->expires_at = $announcement->expires_at?->format('Y-m-d\TH:i') ?? '';
    $this->deadline_at = $announcement->deadline_at?->format('Y-m-d\TH:i') ?? '';
    $this->formOpen = true;
};

$saveAnnouncement = function (string $mode): void {
    $this->requireManager();
    abort_unless(in_array($mode, ['draft', 'published'], true), 422);

    $this->institution_id = $this->institution_id === '' ? null : $this->institution_id;
    $this->published_at = $this->published_at === '' ? null : $this->published_at;
    $this->expires_at = $this->expires_at === '' ? null : $this->expires_at;
    $this->deadline_at = $this->deadline_at === '' ? null : $this->deadline_at;
    $isEditing = filled($this->editingId);
    $existing = $isEditing ? $this->findManagedAnnouncement((int) $this->editingId) : null;
    abort_if($existing?->archived_at !== null, 403, 'Archived announcements cannot be edited. Restore them first.');

    $isAdmin = auth()->user()->hasRole('administrator');
    $validated = $this->validate([
        'institution_id' => [
            $isAdmin ? 'nullable' : 'required',
            'nullable',
            'integer',
            Rule::exists('institutions', 'id')->where('is_active', true),
        ],
        'title' => ['required', 'string', 'max:255'],
        'body' => ['required', 'string', 'max:20000'],
        'audience' => ['required', Rule::in(['all', 'scholar', 'focal_person'])],
        'published_at' => ['nullable', 'date'],
        'expires_at' => ['nullable', 'date'],
        'deadline_at' => ['nullable', 'date'],
    ], [
        'institution_id.required' => 'Select an institution for this announcement.',
        'body.max' => 'The announcement body may not exceed 20,000 characters.',
    ]);

    $institutionId = filled($validated['institution_id']) ? (int) $validated['institution_id'] : null;
    if (! $isAdmin) {
        abort_unless(
            $institutionId !== null && InstitutionScope::idsFor(auth()->user())->contains($institutionId),
            403,
            'You may only publish announcements for your assigned institution.'
        );
    }

    $publishedAt = $mode === 'draft'
        ? null
        : (filled($validated['published_at']) ? Carbon::parse($validated['published_at']) : now());
    $expiresAt = filled($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;
    $deadlineAt = filled($validated['deadline_at']) ? Carbon::parse($validated['deadline_at']) : null;

    $expiryAnchor = $publishedAt ?? now();
    if ($expiresAt && $expiresAt->lessThanOrEqualTo($expiryAnchor)) {
        $this->addError('expires_at', 'Expiry must be later than the publication date and time.');
        return;
    }

    $attributes = [
        'institution_id' => $institutionId,
        'title' => trim($validated['title']),
        'body' => trim($validated['body']),
        'audience' => $validated['audience'],
        'published_at' => $publishedAt,
        'expires_at' => $expiresAt,
        'deadline_at' => $deadlineAt,
        'archived_at' => null,
    ];

    if ($existing) {
        // Snapshot the previous safe fields before updating so the audit record
        // can show exactly what changed, including publication and deadline dates.
        $before = ActivityLogger::snapshot($existing, [
            'institution_id',
            'title',
            'body',
            'audience',
            'published_at',
            'expires_at',
            'deadline_at',
            'archived_at',
        ]);

        $existing->update($attributes);
        $announcement = $existing->refresh();
        $changes = ActivityLogger::changes($announcement, $before);
        $event = 'announcement_updated';
        $description = 'Updated announcement '.$announcement->title;
    } else {
        $changes = [];
        $announcement = Announcement::query()->create([
            ...$attributes,
            'author_user_id' => auth()->id(),
        ]);
        $event = $mode === 'draft' ? 'announcement_draft_created' : 'announcement_published';
        $description = ($mode === 'draft' ? 'Created draft announcement ' : 'Created announcement ').$announcement->title;
    }

    ActivityLogger::record(
        $event,
        Str::limit($description, 250),
        $institutionId,
        ActivityLogger::withChanges([
            'announcement_id' => $announcement->id,
            'audience' => $announcement->audience,
            'published_at' => $announcement->published_at?->toIso8601String(),
            'expires_at' => $announcement->expires_at?->toIso8601String(),
            'deadline_at' => $announcement->deadline_at?->toIso8601String(),
        ], $changes)
    );

    unset($this->announcements);
    $this->resetForm();
    $this->formOpen = false;

    Flux::toast(variant: 'success', text: $mode === 'draft' ? 'Announcement saved as a draft.' : 'Announcement saved successfully.');
};

$archive = function (int $id): void {
    $this->requireManager();
    $announcement = $this->findManagedAnnouncement($id);
    $announcement->update(['archived_at' => now()]);

    ActivityLogger::record(
        'announcement_archived',
        'Archived announcement '.$announcement->title,
        $announcement->institution_id ? (int) $announcement->institution_id : null,
        ['announcement_id' => $announcement->id]
    );

    unset($this->announcements);
    Flux::toast(variant: 'success', text: 'Announcement archived.');
};

$restore = function (int $id): void {
    $this->requireManager();
    $announcement = $this->findManagedAnnouncement($id);
    $announcement->update(['archived_at' => null]);

    ActivityLogger::record(
        'announcement_restored',
        'Restored announcement '.$announcement->title,
        $announcement->institution_id ? (int) $announcement->institution_id : null,
        ['announcement_id' => $announcement->id]
    );

    unset($this->announcements);
    Flux::toast(variant: 'success', text: 'Announcement restored.');
};
?>

@php
    $canManageAnnouncements = $this->canManage;
    $announcementRows = $this->announcements;
    $availableInstitutions = $this->institutions;
@endphp

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-blue-700">Communication</p>
            <flux:heading size="xl" class="mt-1 text-3xl font-black text-slate-900">Announcements</flux:heading>
            <flux:text class="mt-1">Create, schedule, publish, and archive institution announcements. View counts represent unique signed-in users who opened the learner or staff announcements view.</flux:text>
        </div>

        @if ($canManageAnnouncements)
            <flux:button variant="primary" icon="plus" wire:click="openCreate">Create announcement</flux:button>
        @endif
    </div>

    @if ($formOpen && $canManageAnnouncements)
        <flux:card class="bg-white p-5 shadow-sm sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading size="lg">{{ $editingId ? 'Edit announcement' : 'Create announcement' }}</flux:heading>
                    <flux:text class="mt-1">Save as a draft or publish now / schedule for a future time.</flux:text>
                </div>
                <flux:button variant="ghost" icon="x-mark" wire:click="$set('formOpen', false)" aria-label="Close form" />
            </div>

            <form wire:submit="saveAnnouncement('published')" class="mt-6 space-y-5">
                <div class="grid gap-5 md:grid-cols-2">
                    @if (auth()->user()->hasRole('administrator'))
                        <flux:select wire:model="institution_id" label="Institution" placeholder="All institutions (global announcement)">
                            <flux:select.option value="">All institutions</flux:select.option>
                            @foreach ($availableInstitutions as $institution)
                                <flux:select.option :value="$institution->id">{{ $institution->name }} ({{ $institution->code }})</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:select wire:model="institution_id" label="Institution" required>
                            @foreach ($availableInstitutions as $institution)
                                <flux:select.option :value="$institution->id">{{ $institution->name }} ({{ $institution->code }})</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:select wire:model="audience" label="Target audience" required>
                        <flux:select.option value="all">All users</flux:select.option>
                        <flux:select.option value="scholar">Scholars / students</flux:select.option>
                        <flux:select.option value="focal_person">UTPRAS / Focal Persons</flux:select.option>
                    </flux:select>

                    <div class="md:col-span-2">
                        <flux:input wire:model="title" label="Title" placeholder="Announcement title" required />
                    </div>

                    <div class="md:col-span-2">
                        <flux:textarea wire:model="body" label="Message" rows="5" placeholder="Write the announcement details..." required />
                    </div>

                    <flux:input wire:model="published_at" label="Publish date and time (leave blank for now)" type="datetime-local" />
                    <flux:input wire:model="expires_at" label="Expiry date and time (optional)" type="datetime-local" />
                    <flux:input wire:model="deadline_at" label="Student submission deadline (optional)" type="datetime-local" />
                </div>

                @error('expires_at') <p class="text-sm font-medium text-rose-700">{{ $message }}</p> @enderror
                @error('deadline_at') <p class="text-sm font-medium text-rose-700">{{ $message }}</p> @enderror
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('formOpen', false)">Cancel</flux:button>
                    <flux:button type="button" variant="outline" wire:click="saveAnnouncement('draft')">Save draft</flux:button>
                    <flux:button type="submit" variant="primary">Publish / schedule announcement</flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    <flux:card class="bg-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="lg">Announcement list</flux:heading>
                <flux:text class="mt-1">Published, scheduled, draft, expired, and archived messages.</flux:text>
            </div>
            <div class="w-full sm:max-w-xs">
                <flux:select wire:model.live="filterStatus" label="Filter status">
                    <flux:select.option value="all">All statuses</flux:select.option>
                    @if ($canManageAnnouncements)
                        <flux:select.option value="draft">Draft</flux:select.option>
                        <flux:select.option value="scheduled">Scheduled</flux:select.option>
                        <flux:select.option value="published">Published</flux:select.option>
                        <flux:select.option value="expired">Expired</flux:select.option>
                        <flux:select.option value="archived">Archived</flux:select.option>
                    @endif
                </flux:select>
            </div>
        </div>

        <div class="mt-5 space-y-3">
            @forelse ($announcementRows as $announcement)
                @php
                    $status = $announcement->archived_at ? 'Archived' : (is_null($announcement->published_at) ? 'Draft' : ($announcement->published_at->isFuture() ? 'Scheduled' : (($announcement->expires_at && $announcement->expires_at->isPast()) ? 'Expired' : 'Published')));
                    $statusClasses = match ($status) {
                        'Published' => 'bg-emerald-50 text-emerald-700',
                        'Scheduled' => 'bg-blue-50 text-blue-700',
                        'Archived' => 'bg-slate-100 text-slate-600',
                        'Expired' => 'bg-amber-50 text-amber-700',
                        default => 'bg-violet-50 text-violet-700',
                    };
                    $canManageThisAnnouncement = auth()->user()->hasRole('administrator')
                        || ($announcement->institution_id !== null
                            && \App\Support\InstitutionScope::idsFor(auth()->user())->contains((int) $announcement->institution_id));
                @endphp
                <article class="rounded-2xl border border-slate-200 p-4 sm:p-5" wire:key="announcement-{{ $announcement->id }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-bold text-slate-900">{{ $announcement->title }}</h3>
                                <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $statusClasses }}">{{ $status }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $announcement->institution?->name ?? 'All institutions' }} · Audience: {{ match ($announcement->audience) { 'scholar' => 'Scholars / students', 'focal_person' => 'UTPRAS / Focal Persons', default => 'All users' } }} · {{ $announcement->views_count }} unique view(s)
                            </p>
                            <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $announcement->body }}</p>
                            <p class="mt-3 text-xs text-slate-400">
                                {{ $announcement->published_at ? ($announcement->published_at->isFuture() ? 'Scheduled '.$announcement->published_at->format('M j, Y g:i A') : 'Published '.$announcement->published_at->format('M j, Y g:i A')) : 'Not published' }}
                                @if ($announcement->expires_at) · Expires {{ $announcement->expires_at->format('M j, Y g:i A') }} @endif
                                @if ($announcement->deadline_at) · Student deadline {{ $announcement->deadline_at->format('M j, Y g:i A') }} @endif
                                @if ($announcement->author) · By {{ $announcement->author->name }} @endif
                            </p>
                            @if ($canManageAnnouncements && $announcement->views->isNotEmpty())
                                <div class="mt-3 rounded-xl bg-slate-50 p-3">
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Recent viewers (up to 5)</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($announcement->views as $view)
                                            <span class="rounded-lg bg-white px-2.5 py-1.5 text-xs text-slate-600" wire:key="announcement-view-{{ $view->id }}">
                                                {{ $view->user?->name ?? 'Former user' }} · {{ $view->viewed_at?->format('M j, g:i A') }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                        @if ($canManageAnnouncements && $canManageThisAnnouncement)
                            <div class="flex shrink-0 flex-wrap gap-2">
                                @if (! $announcement->archived_at)
                                    <flux:button size="sm" variant="outline" icon="pencil-square" wire:click="edit({{ $announcement->id }})">Edit</flux:button>
                                    <flux:button size="sm" variant="ghost" icon="archive-box" wire:click="archive({{ $announcement->id }})">Archive</flux:button>
                                @else
                                    <flux:button size="sm" variant="outline" icon="arrow-uturn-left" wire:click="restore({{ $announcement->id }})">Restore</flux:button>
                                @endif
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-200 px-5 py-10 text-center">
                    <p class="font-semibold text-slate-700">No announcements found</p>
                    <p class="mt-1 text-sm text-slate-500">Try another status filter or create a new announcement.</p>
                </div>
            @endforelse
        </div>
    </flux:card>
</div>
