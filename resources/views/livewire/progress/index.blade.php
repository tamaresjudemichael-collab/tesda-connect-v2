<?php

use App\Models\TrainingBatch;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Progress & Achievement');

$batches = computed(fn () => TrainingBatch::query()->with('course')->whereIn('institution_id', auth()->user()->institutions()->pluck('institutions.id'))->where('status', 'in_training')->get());
?>

<div class="mx-auto max-w-7xl space-y-6"><div><p class="text-sm font-semibold text-cyan-600">UTPRAS / Focal workspace</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Progress & Achievement</flux:heading><flux:text class="mt-1">Monitor competency task completion without publishing numerical grades to learners.</flux:text></div><div class="grid gap-5 lg:grid-cols-2">@forelse($this->batches as $batch)<flux:card class="bg-white p-6 shadow-sm"><div class="flex items-start justify-between"><div><p class="text-xs font-bold tracking-wide text-cyan-600">{{ $batch->course?->qualification_level }}</p><flux:heading size="lg" class="mt-1">{{ $batch->name }}</flux:heading><flux:text class="mt-1">{{ $batch->course?->name }} · {{ $batch->course?->training_hours }} hours</flux:text></div><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">In training</span></div><div class="mt-6 space-y-4">@foreach ([['Prepare farm tools and materials', 'Competent', 'bg-emerald-500'], ['Perform organic production tasks', 'Submitted output correct', 'bg-cyan-500'], ['Maintain production records', 'For assessment', 'bg-amber-500']] as [$task, $state, $color])<div><div class="mb-1.5 flex justify-between gap-4 text-sm"><span class="font-medium text-slate-700">{{ $task }}</span><span class="shrink-0 text-xs font-bold text-slate-500">{{ $state }}</span></div><div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $color }}" style="width: {{ $state === 'Competent' ? '100' : ($state === 'Submitted output correct' ? '66' : '33') }}%"></div></div></div>@endforeach</div><p class="mt-6 rounded-lg bg-slate-50 p-3 text-xs leading-5 text-slate-500">Learner view shows task status and computed completion only. It does not expose numerical grades or assessor scores.</p></flux:card>@empty <flux:card class="col-span-full p-12 text-center text-slate-400">No active in-training batch is available yet.</flux:card>@endforelse</div></div>
