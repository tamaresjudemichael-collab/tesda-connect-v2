<?php

use App\Models\Institution;
use App\Models\TrainingBatch;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Duration Compliance');

state(['institutionFilter' => 'all', 'complianceFilter' => 'all']);

$institutions = computed(fn () => Institution::query()->orderBy('name')->get());

$batches = computed(function () {
    return TrainingBatch::query()
        ->with(['institution', 'course'])
        ->withCount(['applications as confirmed_enrollees_count' => fn ($query) => $query->where('application_status', 'confirmed')])
        ->when($this->institutionFilter !== 'all', fn ($query) => $query->where('institution_id', $this->institutionFilter))
        ->orderBy('starts_at')->orderBy('name')->get()
        ->map(function (TrainingBatch $batch) {
            $scheduled = $batch->scheduled_hours === null ? null : (int) $batch->scheduled_hours;
            $target = $batch->course?->training_hours === null ? null : (int) $batch->course->training_hours;
            $status = $scheduled === null || $target === null
                ? 'needs_data'
                : ($scheduled < $target ? 'under' : ($scheduled > $target ? 'over' : 'compliant'));
            $batch->setAttribute('duration_compliance', $status);
            $batch->setAttribute('duration_variance', $scheduled === null || $target === null ? null : $scheduled - $target);
            return $batch;
        })
        ->when($this->complianceFilter !== 'all', fn ($items) => $items->where('duration_compliance', $this->complianceFilter)->values());
});

$summary = computed(function () {
    $all = TrainingBatch::query()->with('course')->get();
    return [
        'compliant' => $all->filter(fn ($batch) => $batch->scheduled_hours !== null && $batch->course?->training_hours !== null && (int) $batch->scheduled_hours === (int) $batch->course->training_hours)->count(),
        'under' => $all->filter(fn ($batch) => $batch->scheduled_hours !== null && $batch->course?->training_hours !== null && (int) $batch->scheduled_hours < (int) $batch->course->training_hours)->count(),
        'over' => $all->filter(fn ($batch) => $batch->scheduled_hours !== null && $batch->course?->training_hours !== null && (int) $batch->scheduled_hours > (int) $batch->course->training_hours)->count(),
        'needs_data' => $all->filter(fn ($batch) => $batch->scheduled_hours === null || $batch->course?->training_hours === null)->count(),
    ];
});
?>

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-semibold text-cyan-700">Scheduling management</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Duration Compliance</flux:heading><flux:text class="mt-1">Compare each batch’s planned training hours with its course’s configured training-hour target.</flux:text></div><flux:button href="{{ route('system.batches') }}" wire:navigate variant="primary" icon="pencil-square">Set batch hours</flux:button></div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="p-5"><flux:text>Compliant</flux:text><flux:heading size="xl" class="mt-2 text-emerald-700">{{ $this->summary['compliant'] }}</flux:heading><flux:text class="mt-1 text-xs">Scheduled hours equal course target</flux:text></flux:card>
        <flux:card class="p-5"><flux:text>Under target</flux:text><flux:heading size="xl" class="mt-2 text-amber-700">{{ $this->summary['under'] }}</flux:heading><flux:text class="mt-1 text-xs">Needs duration review</flux:text></flux:card>
        <flux:card class="p-5"><flux:text>Over target</flux:text><flux:heading size="xl" class="mt-2 text-rose-700">{{ $this->summary['over'] }}</flux:heading><flux:text class="mt-1 text-xs">Longer than configured course target</flux:text></flux:card>
        <flux:card class="p-5"><flux:text>Needs data</flux:text><flux:heading size="xl" class="mt-2 text-slate-700">{{ $this->summary['needs_data'] }}</flux:heading><flux:text class="mt-1 text-xs">Batch hours or course target missing</flux:text></flux:card>
    </div>

    <flux:card class="p-5 shadow-sm sm:p-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between"><div><flux:heading size="lg">Compliance report by course and batch</flux:heading><flux:text class="mt-1">This report uses hours configured on each batch and the linked course. It does not infer training hours from calendar days.</flux:text></div><div class="grid gap-3 sm:grid-cols-2"><flux:select wire:model.live="institutionFilter" label="Institution" class="min-w-44"><flux:select.option value="all">All institutions</flux:select.option>@foreach ($this->institutions as $institution)<flux:select.option :value="$institution->id">{{ $institution->code }} — {{ $institution->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model.live="complianceFilter" label="Compliance status" class="min-w-44"><flux:select.option value="all">All statuses</flux:select.option><flux:select.option value="compliant">Compliant</flux:select.option><flux:select.option value="under">Under target</flux:select.option><flux:select.option value="over">Over target</flux:select.option><flux:select.option value="needs_data">Needs data</flux:select.option></flux:select></div></div>
        <div class="mt-5 overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm"><thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-3">Batch / Course</th><th class="px-3 py-3">Institution</th><th class="px-3 py-3">Dates</th><th class="px-3 py-3">Scheduled hours</th><th class="px-3 py-3">Course target</th><th class="px-3 py-3">Variance</th><th class="px-3 py-3">Status</th></tr></thead><tbody class="divide-y divide-slate-100">
        @forelse ($this->batches as $batch)
            <tr wire:key="duration-batch-{{ $batch->id }}"><td class="px-3 py-4"><p class="font-semibold text-slate-900">{{ $batch->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $batch->code }} · {{ $batch->course?->code }} — {{ $batch->course?->name }}</p></td><td class="px-3 py-4"><p class="font-medium">{{ $batch->institution?->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $batch->institution?->code }}</p></td><td class="px-3 py-4 text-slate-600">{{ $batch->starts_at?->format('M d, Y') ?? 'Not set' }}<br>{{ $batch->ends_at?->format('M d, Y') ?? 'Not set' }}</td><td class="px-3 py-4 font-semibold">{{ $batch->scheduled_hours !== null ? number_format($batch->scheduled_hours).' hrs' : 'Not set' }}</td><td class="px-3 py-4 font-semibold">{{ $batch->course?->training_hours !== null ? number_format($batch->course->training_hours).' hrs' : 'Not set' }}</td><td class="px-3 py-4">@if ($batch->duration_variance === null) <span class="text-slate-400">—</span> @elseif ($batch->duration_variance > 0) <span class="font-semibold text-rose-700">+{{ $batch->duration_variance }} hrs</span> @elseif ($batch->duration_variance < 0) <span class="font-semibold text-amber-700">{{ $batch->duration_variance }} hrs</span> @else <span class="font-semibold text-emerald-700">0 hrs</span> @endif</td><td class="px-3 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ match($batch->duration_compliance) {'compliant' => 'bg-emerald-100 text-emerald-800', 'under' => 'bg-amber-100 text-amber-800', 'over' => 'bg-rose-100 text-rose-800', default => 'bg-slate-100 text-slate-600'} }}">{{ match($batch->duration_compliance) {'compliant' => 'Compliant', 'under' => 'Under target', 'over' => 'Over target', default => 'Needs data'} }}</span></td></tr>
        @empty <tr><td colspan="7" class="px-3 py-10 text-center text-slate-400">No batches match the selected filters.</td></tr> @endforelse
        </tbody></table></div>
    </flux:card>
</div>
