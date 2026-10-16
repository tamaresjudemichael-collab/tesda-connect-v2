
<?php

use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Models\TrainingBatch;
use App\Support\InstitutionScope;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Reports & Oversight');

$report = computed(function (): array {
    $institutionIds = InstitutionScope::idsFor(auth()->user());

    $applications = EnrollmentApplication::query()
        ->whereIn('institution_id', $institutionIds);

    $total = (clone $applications)->count();
    $complete = (clone $applications)->where('record_completeness', 100)->count();

    return [
        'total' => $total,
        'complete' => $complete,
        'pending' => (clone $applications)
            ->whereIn('application_status', ['under_review', 'returned_for_revision'])
            ->count(),
        'batches' => TrainingBatch::whereIn('institution_id', $institutionIds)->count(),
        'readiness' => $total ? (int) round($complete / $total * 100) : 0,
    ];
});

$institutionReports = computed(function (): array {
    $institutionIds = InstitutionScope::idsFor(auth()->user());

    return Institution::query()
        ->whereIn('id', $institutionIds)
        ->orderBy('name')
        ->get()
        ->map(function (Institution $institution): array {
            $applications = EnrollmentApplication::query()
                ->where('institution_id', $institution->id);

            return [
                'id' => $institution->id,
                'code' => $institution->code,
                'name' => $institution->name,
                'total' => (clone $applications)->count(),
                'complete' => (clone $applications)
                    ->where('record_completeness', 100)
                    ->count(),
                'pending' => (clone $applications)
                    ->whereIn('application_status', ['under_review', 'returned_for_revision'])
                    ->count(),
                'confirmed' => (clone $applications)
                    ->where('application_status', 'confirmed')
                    ->count(),
                'batches' => TrainingBatch::where('institution_id', $institution->id)->count(),
            ];
        })
        ->all();
});
?>

@php
    $data = $this->report;
    $institutionRows = $this->institutionReports;
@endphp

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-cyan-600">Operations insight</p>
            <flux:heading size="xl" class="mt-1 text-3xl font-black">
                Reports & Oversight
            </flux:heading>
            <flux:text class="mt-1">
                Monitor enrollment readiness and training operations by institution.
            </flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button href="{{ route('reports.export', ['type' => 'enrollments']) }}" icon="document-arrow-down">
                Export enrollments CSV
            </flux:button>
            <flux:button href="{{ route('reports.export', ['type' => 'competency-progress']) }}" icon="document-arrow-down">
                Export competency CSV
            </flux:button>
            <flux:button href="{{ route('reports.export', ['type' => 'attendance']) }}" icon="document-arrow-down">
                Export attendance CSV
            </flux:button>
            <flux:button variant="subtle" wire:click="$refresh">
                Refresh report
            </flux:button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Learner records', $data['total'], 'text-blue-700 bg-blue-50'],
            ['Complete records', $data['complete'], 'text-emerald-700 bg-emerald-50'],
            ['Needs action', $data['pending'], 'text-amber-700 bg-amber-50'],
            ['Managed batches', $data['batches'], 'text-violet-700 bg-violet-50'],
        ] as [$label, $value, $style])
            <flux:card class="bg-white p-5 shadow-sm">
                <span class="inline-flex rounded-lg px-3 py-1 text-xs font-bold {{ $style }}">
                    {{ $label }}
                </span>
                <p class="mt-4 text-4xl font-black text-[#102f68]">
                    {{ $value }}
                </p>
            </flux:card>
        @endforeach
    </div>

    <flux:card class="bg-white p-5 shadow-sm sm:p-6">
        <div class="mb-5">
            <flux:heading size="lg">Institution performance</flux:heading>
            <flux:text class="mt-1">
                Enrollment records, completeness, confirmed scholars, and batches for each assigned institution.
            </flux:text>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[850px] text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-3">Institution</th>
                        <th class="px-3 py-3 text-center">Learner records</th>
                        <th class="px-3 py-3 text-center">Complete</th>
                        <th class="px-3 py-3 text-center">Needs action</th>
                        <th class="px-3 py-3 text-center">Confirmed</th>
                        <th class="px-3 py-3 text-center">Batches</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse ($institutionRows as $row)
                        <tr wire:key="institution-report-{{ $row['id'] }}">
                            <td class="px-3 py-4">
                                <p class="font-bold text-slate-900">
                                    {{ $row['name'] }}
                                </p>
                                <p class="mt-1 text-xs font-semibold text-blue-700">
                                    {{ $row['code'] }}
                                </p>
                            </td>

                            <td class="px-3 py-4 text-center font-semibold text-slate-700">
                                {{ $row['total'] }}
                            </td>

                            <td class="px-3 py-4 text-center">
                                <span class="font-semibold text-emerald-700">
                                    {{ $row['complete'] }}
                                </span>
                            </td>

                            <td class="px-3 py-4 text-center">
                                <span class="font-semibold {{ $row['pending'] ? 'text-amber-700' : 'text-slate-500' }}">
                                    {{ $row['pending'] }}
                                </span>
                            </td>

                            <td class="px-3 py-4 text-center font-semibold text-slate-700">
                                {{ $row['confirmed'] }}
                            </td>

                            <td class="px-3 py-4 text-center font-semibold text-slate-700">
                                {{ $row['batches'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-10 text-center text-slate-500">
                                No assigned institutions are available for reporting.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

    <flux:card class="bg-white p-5 shadow-sm sm:p-6">
        <div class="grid gap-8 md:grid-cols-[1fr_1.3fr]">
            <div>
                <flux:heading size="lg">Report readiness</flux:heading>

                <p class="mt-3 text-sm leading-6 text-slate-500">
                    Records are ready when the required profile information and supporting documents are complete.
                </p>

                <p class="mt-7 text-5xl font-black text-[#102f68]">
                    {{ $data['readiness'] }}%
                </p>

                <p class="mt-1 text-sm font-semibold text-slate-500">
                    records marked complete
                </p>
            </div>

            <div class="space-y-4">
                <div class="rounded-xl border border-emerald-100 bg-emerald-50 p-4">
                    <p class="font-semibold text-emerald-800">Ready now</p>
                    <p class="mt-1 text-sm text-emerald-700">
                        {{ $data['complete'] }} learner record(s) are marked 100% complete.
                    </p>
                </div>

                <div class="rounded-xl border border-amber-100 bg-amber-50 p-4">
                    <p class="font-semibold text-amber-800">Attention needed</p>
                    <p class="mt-1 text-sm text-amber-700">
                        {{ $data['pending'] }} record(s) are under review or returned for revision.
                    </p>
                </div>
            </div>
        </div>
    </flux:card>
</div>
