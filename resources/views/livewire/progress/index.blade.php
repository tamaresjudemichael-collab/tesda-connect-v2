
<?php

use App\Models\EnrollmentApplication;
use App\Support\InstitutionScope;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Scholar Monitoring');

$applications = computed(function () {
    $institutionIds = InstitutionScope::idsFor(auth()->user());

    return EnrollmentApplication::query()
        ->with(['institution', 'course', 'trainingBatch'])
        ->whereIn('institution_id', $institutionIds)
        ->where('application_status', 'confirmed')
        ->orderBy('institution_id')
        ->orderBy('learner_name')
        ->get();
});
?>

@php
    $scholars = $this->applications;
    $totalScholars = $scholars->count();
    $assignedToBatch = $scholars->filter(fn ($scholar) => $scholar->training_batch_id !== null)->count();
    $unassignedToBatch = $totalScholars - $assignedToBatch;
@endphp

<div class="mx-auto max-w-7xl space-y-6">
    <div>
        <p class="text-sm font-semibold text-cyan-600">Training Operations</p>
        <flux:heading size="xl" class="mt-1 text-3xl font-black">
            Scholar Monitoring
        </flux:heading>
        <flux:text class="mt-1">
            Monitor confirmed scholars, institution assignments, courses, and training batches.
        </flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="bg-white p-5 shadow-sm">
            <flux:text>Confirmed scholars</flux:text>
            <p class="mt-3 text-4xl font-black text-[#102f68]">
                {{ $totalScholars }}
            </p>
        </flux:card>

        <flux:card class="bg-white p-5 shadow-sm">
            <flux:text>Assigned to batches</flux:text>
            <p class="mt-3 text-4xl font-black text-emerald-700">
                {{ $assignedToBatch }}
            </p>
        </flux:card>

        <flux:card class="bg-white p-5 shadow-sm">
            <flux:text>Batch assignment needed</flux:text>
            <p class="mt-3 text-4xl font-black text-amber-700">
                {{ $unassignedToBatch }}
            </p>
        </flux:card>
    </div>

    <flux:card class="bg-white p-5 shadow-sm sm:p-6">
        <div class="mb-5">
            <flux:heading size="lg">Confirmed scholar records</flux:heading>
            <flux:text class="mt-1">
                Only confirmed enrollments from your assigned institutions are shown.
            </flux:text>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[1000px] text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-3">Scholar</th>
                        <th class="px-3 py-3">Institution</th>
                        <th class="px-3 py-3">Course</th>
                        <th class="px-3 py-3">Training batch</th>
                        <th class="px-3 py-3">Scholar ID</th>
                        <th class="px-3 py-3">Confirmed date</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse ($scholars as $scholar)
                        <tr wire:key="scholar-monitor-{{ $scholar->id }}">
                            <td class="px-3 py-4">
                                <p class="font-semibold text-slate-900">
                                    {{ $scholar->learner_name }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $scholar->email ?: 'No email provided' }}
                                </p>
                            </td>

                            <td class="px-3 py-4">
                                <p class="font-semibold text-slate-800">
                                    {{ $scholar->institution?->code ?? 'N/A' }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $scholar->institution?->name ?? 'Institution unavailable' }}
                                </p>
                            </td>

                            <td class="px-3 py-4 text-slate-700">
                                {{ $scholar->course?->name ?? 'Course not assigned' }}
                            </td>

                            <td class="px-3 py-4">
                                @if ($scholar->trainingBatch)
                                    <p class="font-semibold text-slate-800">
                                        {{ $scholar->trainingBatch->name }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $scholar->trainingBatch->code }}
                                    </p>
                                @else
                                    <span class="text-amber-700">
                                        Batch not assigned
                                    </span>
                                @endif
                            </td>

                            <td class="px-3 py-4">
                                <span class="font-semibold text-blue-700">
                                    {{ $scholar->scholar_id ?: 'Not generated' }}
                                </span>
                            </td>

                            <td class="px-3 py-4 text-slate-600">
                                {{ $scholar->confirmed_at?->format('M d, Y') ?? 'Not available' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-12 text-center">
                                <p class="font-semibold text-slate-700">
                                    No confirmed scholars found
                                </p>
                                <p class="mt-1 text-slate-500">
                                    Confirmed enrollments will appear here when available.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
