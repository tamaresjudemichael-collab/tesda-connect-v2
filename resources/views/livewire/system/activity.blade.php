<?php

use App\Models\ActivityLog;
use App\Models\Institution;
use App\Models\User;
use function Livewire\Volt\{computed, layout, state, title, updating, usesPagination};

usesPagination();
layout('layouts::app');
title('Audit Logs');

state([
    'institutionFilter' => 'all',
    'eventFilter' => 'all',
    'userFilter' => 'all',
    'moduleFilter' => 'all',
    'startDate' => '',
    'endDate' => '',
]);

updating([
    'institutionFilter' => fn () => $this->resetPage(),
    'eventFilter' => fn () => $this->resetPage(),
    'userFilter' => fn () => $this->resetPage(),
    'moduleFilter' => fn () => $this->resetPage(),
    'startDate' => fn () => $this->resetPage(),
    'endDate' => fn () => $this->resetPage(),
]);

$institutions = computed(fn () => Institution::query()->orderBy('name')->get());

$events = computed(fn () => ActivityLog::query()
    ->whereNotNull('event')
    ->distinct()
    ->orderBy('event')
    ->pluck('event'));

$modules = computed(fn () => $this->events
    ->map(fn ($event) => \App\Support\AuditLogPresenter::moduleForEvent((string) $event))
    ->unique()
    ->sort()
    ->values());

$users = computed(fn () => User::query()
    ->whereIn(
        'id',
        ActivityLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id')
    )
    ->orderBy('name')
    ->get(['id', 'name', 'role']));

$activities = computed(function () {
    $query = ActivityLog::query()->with(['user', 'institution']);

    $query
        ->when(
            $this->institutionFilter !== 'all',
            fn ($query) => $query->where('institution_id', (int) $this->institutionFilter)
        )
        ->when(
            $this->eventFilter !== 'all',
            fn ($query) => $query->where('event', $this->eventFilter)
        )
        ->when(
            $this->userFilter !== 'all',
            fn ($query) => $query->where('user_id', (int) $this->userFilter)
        )
        ->when(
            $this->startDate !== '',
            fn ($query) => $query->whereDate('created_at', '>=', $this->startDate)
        )
        ->when(
            $this->endDate !== '',
            fn ($query) => $query->whereDate('created_at', '<=', $this->endDate)
        )
        ->when($this->moduleFilter !== 'all', function ($query) {
            $selectedModule = $this->moduleFilter;
            $matchingEvents = ActivityLog::query()
                ->whereNotNull('event')
                ->distinct()
                ->pluck('event')
                ->filter(fn ($event) => \App\Support\AuditLogPresenter::moduleForEvent((string) $event) === $selectedModule)
                ->values()
                ->all();

            $query->whereIn('event', $matchingEvents);
        });

    return $query->latest('created_at')->latest('id')->paginate(10);
});

$resetFilters = function (): void {
    $this->institutionFilter = 'all';
    $this->eventFilter = 'all';
    $this->userFilter = 'all';
    $this->moduleFilter = 'all';
    $this->startDate = '';
    $this->endDate = '';
    $this->resetPage();
};

$exportCsv = function (): \Symfony\Component\HttpFoundation\StreamedResponse {
    $query = ActivityLog::query()->with(['user', 'institution']);

    $query
        ->when(
            $this->institutionFilter !== 'all',
            fn ($query) => $query->where('institution_id', (int) $this->institutionFilter)
        )
        ->when(
            $this->eventFilter !== 'all',
            fn ($query) => $query->where('event', $this->eventFilter)
        )
        ->when(
            $this->userFilter !== 'all',
            fn ($query) => $query->where('user_id', (int) $this->userFilter)
        )
        ->when(
            $this->startDate !== '',
            fn ($query) => $query->whereDate('created_at', '>=', $this->startDate)
        )
        ->when(
            $this->endDate !== '',
            fn ($query) => $query->whereDate('created_at', '<=', $this->endDate)
        )
        ->when($this->moduleFilter !== 'all', function ($query) {
            $selectedModule = $this->moduleFilter;
            $matchingEvents = ActivityLog::query()
                ->whereNotNull('event')
                ->distinct()
                ->pluck('event')
                ->filter(fn ($event) => \App\Support\AuditLogPresenter::moduleForEvent((string) $event) === $selectedModule)
                ->values()
                ->all();

            $query->whereIn('event', $matchingEvents);
        });

    return response()->streamDownload(function () use ($query): void {
        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            return;
        }

        // UTF-8 BOM improves opening the export in desktop spreadsheet apps.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'Timestamp', 'User', 'Role', 'Institution', 'Module', 'Action Type',
            'Description', 'From Value', 'To Value', 'Metadata',
        ]);

        $query->orderBy('created_at')->orderBy('id')->chunk(500, function ($rows) use ($handle): void {
            foreach ($rows as $activity) {
                fputcsv($handle, [
                    $activity->created_at?->toIso8601String() ?? '',
                    $activity->user?->name ?? 'System / Unknown user',
                    $activity->user?->roleLabel() ?? '',
                    $activity->institution?->code ?? 'Platform',
                    \App\Support\AuditLogPresenter::moduleForEvent((string) $activity->event),
                    $activity->event,
                    $activity->description,
                    \App\Support\AuditLogPresenter::auditValue($activity->metadata, ['from', 'old_value', 'before']),
                    \App\Support\AuditLogPresenter::auditValue($activity->metadata, ['to', 'new_value', 'after']),
                    json_encode($activity->metadata ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                ]);
            }
        });

        fclose($handle);
    }, 'tesda-audit-logs-'.now()->format('Ymd-His').'.csv', [
        'Content-Type' => 'text/csv; charset=UTF-8',
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
    ]);
};
?>

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-cyan-700">System Monitoring</p>
            <flux:heading size="xl" class="mt-1 text-3xl font-black">Audit Logs</flux:heading>
            <flux:text class="mt-1">
                Review system activity across institutions, filter the history, and export matching records.
            </flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button variant="ghost" icon="arrow-path" wire:click="$refresh">Refresh logs</flux:button>
            <flux:button variant="primary" icon="arrow-down-tray" wire:click="exportCsv">Export CSV</flux:button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="bg-emerald-50 p-5">
            <p class="text-sm font-bold text-emerald-700">Platform status</p>
            <p class="mt-2 text-2xl font-black text-emerald-800">Online</p>
            <p class="mt-1 text-xs text-emerald-700">Application is responding to this request.</p>
        </flux:card>

        <flux:card class="bg-blue-50 p-5">
            <p class="text-sm font-bold text-blue-700">Queue driver</p>
            <p class="mt-2 text-2xl font-black text-blue-800">{{ config('queue.default') }}</p>
        </flux:card>

        <flux:card class="bg-violet-50 p-5">
            <p class="text-sm font-bold text-violet-700">Matching audit events</p>
            <p class="mt-2 text-2xl font-black text-violet-800">{{ $this->activities->total() }}</p>
            <p class="mt-1 text-xs text-violet-700">Across all pages for the current filters.</p>
        </flux:card>
    </div>

    <flux:card class="bg-white p-5 shadow-sm sm:p-6">
        <div class="mb-5">
            <flux:heading size="lg">Filter audit activity</flux:heading>
            <flux:text class="mt-1">Filter by user, institution, module, action type, or date range.</flux:text>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <flux:select wire:model.live="institutionFilter" label="Institution">
                <flux:select.option value="all">All institutions</flux:select.option>
                @foreach ($this->institutions as $institution)
                    <flux:select.option :value="$institution->id">
                        {{ $institution->code }} — {{ $institution->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="userFilter" label="User">
                <flux:select.option value="all">All users</flux:select.option>
                @foreach ($this->users as $user)
                    <flux:select.option :value="$user->id">
                        {{ $user->name }}{{ $user->role ? ' — '.str($user->role)->replace('_', ' ')->headline() : '' }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="moduleFilter" label="Module">
                <flux:select.option value="all">All modules</flux:select.option>
                @foreach ($this->modules as $module)
                    <flux:select.option :value="$module">{{ $module }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="eventFilter" label="Action type">
                <flux:select.option value="all">All actions</flux:select.option>
                @foreach ($this->events as $event)
                    <flux:select.option :value="$event">
                        {{ str($event)->replace('_', ' ')->headline() }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model.live="startDate" label="From date" />
            <flux:input type="date" wire:model.live="endDate" label="To date" />
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-slate-500">The selected dates are inclusive. Export CSV uses the same filters.</p>
            <flux:button variant="ghost" wire:click="resetFilters">Clear filters</flux:button>
        </div>
    </flux:card>

    <flux:card class="bg-white p-5 shadow-sm sm:p-6">
        <div class="mb-4">
            <flux:heading size="lg">Activity history</flux:heading>
            <flux:text class="mt-1">Newest events appear first. Where logged metadata includes change values, the previous and new values are shown.</flux:text>
        </div>

        <div class="divide-y divide-slate-100">
            @forelse ($this->activities as $activity)
                @php
                    // Keep this formatting local to the Blade row. Volt component-scope
                    // closures are not guaranteed to be exposed as Blade template variables.
                    $metadata = is_array($activity->metadata) ? $activity->metadata : [];
                    $changeItems = \App\Support\AuditLogPresenter::changeEntries($metadata);
                    $fromValue = '';
                    $toValue = '';

                    foreach (['from', 'old_value', 'before'] as $metadataKey) {
                        if (array_key_exists($metadataKey, $metadata)) {
                            $value = $metadata[$metadataKey];
                            $fromValue = $value === null
                                ? ''
                                : (is_scalar($value)
                                    ? (string) $value
                                    : (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''));
                            break;
                        }
                    }

                    foreach (['to', 'new_value', 'after'] as $metadataKey) {
                        if (array_key_exists($metadataKey, $metadata)) {
                            $value = $metadata[$metadataKey];
                            $toValue = $value === null
                                ? ''
                                : (is_scalar($value)
                                    ? (string) $value
                                    : (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''));
                            break;
                        }
                    }
                @endphp
                <article wire:key="activity-{{ $activity->id }}" class="flex gap-4 py-4">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-600">
                        <flux:icon icon="clipboard-document-check" class="size-5" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <p class="font-semibold text-slate-800">{{ $activity->description }}</p>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                {{ \App\Support\AuditLogPresenter::moduleForEvent((string) $activity->event) }}
                            </span>
                        </div>

                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                            <span>User: {{ $activity->user?->name ?? 'System / Unknown user' }}</span>
                            @if ($activity->user?->role)
                                <span>Role: {{ $activity->user->roleLabel() }}</span>
                            @endif
                            <span>Institution: {{ $activity->institution?->code ?? 'Platform' }}</span>
                            <span>Action: {{ str($activity->event)->replace('_', ' ')->headline() }}</span>
                        </div>

                        @if (count($changeItems))
                            <div class="mt-3 space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                                <p class="font-semibold text-slate-600">Changed fields</p>
                                @foreach ($changeItems as $changeItem)
                                    <div wire:key="activity-{{ $activity->id }}-change-{{ $loop->index }}" class="grid gap-1 sm:grid-cols-[10rem_minmax(0,1fr)]">
                                        <span class="font-medium text-slate-600">{{ $changeItem['field'] }}</span>
                                        <span class="break-words">
                                            <span class="text-slate-500">{{ $changeItem['from'] !== '' ? $changeItem['from'] : '—' }}</span>
                                            <span class="mx-2 text-slate-400">→</span>
                                            <span class="font-semibold text-slate-800">{{ $changeItem['to'] !== '' ? $changeItem['to'] : '—' }}</span>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @elseif ($fromValue !== '' || $toValue !== '')
                            <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                                <span class="font-semibold text-slate-600">Change:</span>
                                <span class="ml-1 text-slate-600">{{ $fromValue !== '' ? $fromValue : '—' }}</span>
                                <span class="mx-2 text-slate-400">→</span>
                                <span class="font-semibold text-slate-800">{{ $toValue !== '' ? $toValue : '—' }}</span>
                            </div>
                        @endif

                        @if (is_array($activity->metadata) && count($activity->metadata))
                            <details class="mt-2 text-xs text-slate-500">
                                <summary class="cursor-pointer font-semibold hover:text-slate-700">View event metadata</summary>
                                <pre class="mt-2 max-w-full overflow-x-auto rounded bg-slate-50 p-3 whitespace-pre-wrap">{{ json_encode($activity->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        @endif

                        <p class="mt-2 text-xs text-slate-400">
                            {{ $activity->created_at->format('M d, Y h:i A') }} · {{ $activity->created_at->diffForHumans() }}
                        </p>
                    </div>
                </article>
            @empty
                <div class="py-12 text-center">
                    <flux:icon icon="magnifying-glass" class="mx-auto size-8 text-slate-300" />
                    <p class="mt-3 font-semibold text-slate-700">No matching audit events</p>
                    <p class="mt-1 text-sm text-slate-500">Try another filter or clear the current filters.</p>
                </div>
            @endforelse
        </div>

        @if ($this->activities->hasPages())
            <div class="mt-6 border-t border-slate-100 pt-4">
                {{ $this->activities->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </flux:card>
</div>
