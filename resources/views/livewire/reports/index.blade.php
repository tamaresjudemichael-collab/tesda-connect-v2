<?php

use App\Models\EnrollmentApplication;
use App\Models\TrainingBatch;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Reports & Oversight');

$report = computed(function (): array {
    $institutionIds = auth()->user()->institutions()->pluck('institutions.id');
    $applications = EnrollmentApplication::whereIn('institution_id', $institutionIds);
    $total = (clone $applications)->count();
    $complete = (clone $applications)->where('record_completeness', 100)->count();
    return ['total' => $total, 'complete' => $complete, 'pending' => (clone $applications)->whereIn('application_status', ['under_review', 'returned_for_revision'])->count(), 'batches' => TrainingBatch::whereIn('institution_id', $institutionIds)->count(), 'readiness' => $total ? (int) round($complete / $total * 100) : 0];
});
?>

@php($data = $this->report)
<div class="mx-auto max-w-7xl space-y-6"><div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-semibold text-cyan-600">Operations insight</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Reports & Oversight</flux:heading><flux:text class="mt-1">A clear readiness view for operational reporting.</flux:text></div><flux:button variant="primary" icon="document-arrow-down">Generate summary</flux:button></div><div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">@foreach ([['Learner records', $data['total'], 'text-blue-700 bg-blue-50'], ['Complete records', $data['complete'], 'text-emerald-700 bg-emerald-50'], ['Needs action', $data['pending'], 'text-amber-700 bg-amber-50'], ['Managed batches', $data['batches'], 'text-violet-700 bg-violet-50']] as [$label, $value, $style])<flux:card class="bg-white p-5 shadow-sm"><span class="inline-flex rounded-lg px-3 py-1 text-xs font-bold {{ $style }}">{{ $label }}</span><p class="mt-4 text-4xl font-black text-[#102f68]">{{ $value }}</p></flux:card>@endforeach</div><flux:card class="bg-white p-6 shadow-sm"><div class="grid gap-8 md:grid-cols-[1fr_1.3fr]"><div><flux:heading size="lg">Report readiness</flux:heading><p class="mt-3 text-sm leading-6 text-slate-500">Records are ready when required profile and supporting documents have been verified by staff.</p><p class="mt-7 text-5xl font-black text-[#102f68]">{{ $data['readiness'] }}%</p><p class="mt-1 text-sm font-semibold text-slate-500">verified and complete</p></div><div class="space-y-4"><div class="rounded-xl border border-emerald-100 bg-emerald-50 p-4"><p class="font-semibold text-emerald-800">Ready now</p><p class="mt-1 text-sm text-emerald-700">{{ $data['complete'] }} learner record(s) can be included in a complete report.</p></div><div class="rounded-xl border border-amber-100 bg-amber-50 p-4"><p class="font-semibold text-amber-800">Attention needed</p><p class="mt-1 text-sm text-amber-700">{{ $data['pending'] }} record(s) still need review or learner revision.</p></div></div></div></flux:card></div>
