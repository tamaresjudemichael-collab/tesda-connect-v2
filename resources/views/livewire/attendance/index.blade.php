<?php

use App\Models\AttendanceRecord;
use App\Models\EnrollmentApplication;
use App\Models\TrainingBatch;
use App\Support\InstitutionScope;
use Flux\Flux;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Attendance Management');

state([
    'batchId' => null,
    'attendanceDate' => now()->toDateString(),
    'search' => '',
    'attendanceStatus' => [],
    'attendanceRemarks' => [],
]);

$institutionIds = computed(fn () => InstitutionScope::idsFor(auth()->user()));

$batches = computed(function () {
    $user = auth()->user();

    abort_unless(
        $user && $user->account_status === 'active' && $user->hasRole('administrator', 'focal_person'),
        403
    );

    return TrainingBatch::query()
        ->with(['course', 'institution'])
        ->whereIn('institution_id', $this->institutionIds)
        ->orderByDesc('starts_at')
        ->orderBy('name')
        ->get();
});

$selectedBatch = computed(function () {
    if (! $this->batchId) {
        return null;
    }

    return $this->batches->firstWhere('id', (int) $this->batchId);
});

$students = computed(function () {
    if (! $this->selectedBatch) {
        return collect();
    }

    return EnrollmentApplication::query()
        ->with(['attendanceRecords' => fn ($query) => $query->whereDate('attendance_date', $this->attendanceDate)])
        ->where('training_batch_id', $this->selectedBatch->id)
        ->where('institution_id', $this->selectedBatch->institution_id)
        ->where('application_status', 'confirmed')
        ->orderBy('learner_name')
        ->get();
});

$visibleStudents = computed(function () {
    $search = trim((string) $this->search);

    if ($search === '') {
        return $this->students;
    }

    return $this->students->filter(function ($student) use ($search) {
        return str_contains(mb_strtolower($student->learner_name), mb_strtolower($search))
            || str_contains(mb_strtolower((string) $student->scholar_id), mb_strtolower($search))
            || str_contains(mb_strtolower($student->reference_no), mb_strtolower($search));
    })->values();
});

$loadAttendance = function (): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('administrator', 'focal_person'), 403);

    $this->attendanceStatus = [];
    $this->attendanceRemarks = [];

    if (! $this->batchId) {
        unset($this->students, $this->visibleStudents, $this->selectedBatch);
        return;
    }

    $batch = TrainingBatch::query()
        ->whereKey((int) $this->batchId)
        ->whereIn('institution_id', $this->institutionIds)
        ->first();

    abort_unless($batch, 403);

    $this->validate([
        'attendanceDate' => ['required', 'date'],
    ]);

    $applications = EnrollmentApplication::query()
        ->with(['attendanceRecords' => fn ($query) => $query->whereDate('attendance_date', $this->attendanceDate)])
        ->where('training_batch_id', $batch->id)
        ->where('institution_id', $batch->institution_id)
        ->where('application_status', 'confirmed')
        ->get();

    foreach ($applications as $application) {
        $record = $application->attendanceRecords->first();
        $this->attendanceStatus[$application->id] = $record?->status ?? '';
        $this->attendanceRemarks[$application->id] = $record?->remarks ?? '';
    }

    unset($this->students, $this->visibleStudents, $this->selectedBatch);
};

$updatedBatchId = function (): void {
    $this->loadAttendance();
};

$updatedAttendanceDate = function (): void {
    $this->loadAttendance();
};

$save = function (): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('administrator', 'focal_person'), 403);

    $this->validate([
        'batchId' => ['required', 'integer'],
        'attendanceDate' => ['required', 'date', 'before_or_equal:today'],
        'search' => ['nullable', 'string', 'max:100'],
        'attendanceStatus' => ['array'],
        'attendanceRemarks' => ['array'],
    ]);

    $batch = TrainingBatch::query()
        ->whereKey((int) $this->batchId)
        ->whereIn('institution_id', $this->institutionIds)
        ->first();

    abort_unless($batch, 403);

    $studentIds = $this->visibleStudents->pluck('id')->map(fn ($id) => (int) $id)->all();
    abort_if(empty($studentIds), 422, 'There are no confirmed trainees to update for the current filter.');

    $allowedStatuses = ['present', 'absent', 'late'];

    $this->resetValidation();

    foreach ($studentIds as $applicationId) {
        $status = $this->attendanceStatus[$applicationId] ?? '';

        if (! in_array($status, $allowedStatuses, true)) {
            $this->addError("attendanceStatus.{$applicationId}", 'Select Present, Absent, or Late.');
            continue;
        }

        $remarks = $this->attendanceRemarks[$applicationId] ?? null;
        if ($remarks !== null && mb_strlen((string) $remarks) > 1000) {
            $this->addError("attendanceRemarks.{$applicationId}", 'Remarks cannot exceed 1000 characters.');
            continue;
        }
    }

    if (count($this->getErrorBag()->getMessages()) > 0) {
        return;
    }

    foreach ($studentIds as $applicationId) {
        $application = EnrollmentApplication::query()
            ->whereKey($applicationId)
            ->where('training_batch_id', $batch->id)
            ->where('institution_id', $batch->institution_id)
            ->where('application_status', 'confirmed')
            ->firstOrFail();

        AttendanceRecord::query()->updateOrCreate(
            [
                'enrollment_application_id' => $application->id,
                'attendance_date' => $this->attendanceDate,
            ],
            [
                'status' => $this->attendanceStatus[$application->id],
                'remarks' => filled($this->attendanceRemarks[$application->id] ?? null)
                    ? trim((string) $this->attendanceRemarks[$application->id])
                    : null,
                'recorded_by_user_id' => $user->id,
            ]
        );
    }

    unset($this->students, $this->visibleStudents, $this->selectedBatch);
    $this->loadAttendance();
    Flux::toast(variant: 'success', text: 'Attendance saved for the listed trainees.');
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-cyan-700">Training operations</p>
            <h1 class="mt-1 text-3xl font-black tracking-tight text-slate-900">Attendance Management</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Record Present, Absent, or Late for confirmed trainees in an assigned training batch. Every saved record includes its date and recording staff member.</p>
        </div>
    </header>

    <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-3">
        <div>
            <label for="attendance-batch" class="mb-1 block text-sm font-semibold text-slate-700">Training batch</label>
            <select id="attendance-batch" wire:model.live="batchId" class="w-full rounded-xl border-slate-300 text-sm focus:border-blue-600 focus:ring-blue-600">
                <option value="">Select a batch</option>
                @foreach ($this->batches as $batch)
                    <option value="{{ $batch->id }}">{{ $batch->code }} — {{ $batch->name }} ({{ $batch->institution?->code }})</option>
                @endforeach
            </select>
            @error('batchId') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="attendance-date" class="mb-1 block text-sm font-semibold text-slate-700">Attendance date</label>
            <input id="attendance-date" type="date" wire:model.live="attendanceDate" max="{{ now()->toDateString() }}" class="w-full rounded-xl border-slate-300 text-sm focus:border-blue-600 focus:ring-blue-600">
            @error('attendanceDate') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="attendance-search" class="mb-1 block text-sm font-semibold text-slate-700">Find trainee</label>
            <input id="attendance-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Name, Scholar ID, reference" class="w-full rounded-xl border-slate-300 text-sm focus:border-blue-600 focus:ring-blue-600">
        </div>
    </section>

    @if ($this->selectedBatch)
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-2 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="font-bold text-slate-900">{{ $this->selectedBatch->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $this->selectedBatch->course?->name }} · {{ $this->selectedBatch->institution?->name }} · {{ \Carbon\Carbon::parse($attendanceDate)->format('F d, Y') }}</p>
                </div>
                <span class="text-sm text-slate-500">{{ $this->visibleStudents->count() }} trainee(s) shown</span>
            </div>

            <form wire:submit="save">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-5 py-3">Trainee</th><th class="px-5 py-3">Attendance</th><th class="px-5 py-3">Remarks (optional)</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($this->visibleStudents as $student)
                                <tr wire:key="attendance-row-{{ $student->id }}">
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-slate-900">{{ $student->learner_name }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $student->scholar_id ?? $student->reference_no }}</p>
                                    </td>
                                    <td class="min-w-48 px-5 py-4">
                                        <select wire:model="attendanceStatus.{{ $student->id }}" aria-label="Attendance status for {{ $student->learner_name }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-blue-600 focus:ring-blue-600">
                                            <option value="">Choose status</option>
                                            <option value="present">Present</option>
                                            <option value="absent">Absent</option>
                                            <option value="late">Late</option>
                                        </select>
                                        @error('attendanceStatus.'.$student->id) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                    </td>
                                    <td class="min-w-64 px-5 py-4">
                                        <input type="text" wire:model="attendanceRemarks.{{ $student->id }}" maxlength="1000" aria-label="Remarks for {{ $student->learner_name }}" placeholder="Optional note" class="w-full rounded-lg border-slate-300 text-sm focus:border-blue-600 focus:ring-blue-600">
                                        @error('attendanceRemarks.'.$student->id) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-5 py-12 text-center text-slate-500">No confirmed trainees found for this batch or search.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-5 text-slate-500">Saving updates the attendance record for this trainee and date. Filtered results save only the trainees currently shown.</p>
                    <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-xl bg-blue-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-800 disabled:opacity-50">Save attendance</button>
                </div>
            </form>
        </section>
    @else
        <section class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
            <h2 class="font-bold text-slate-800">Select a training batch</h2>
            <p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-500">Only batches in your permitted institution scope are available. The list includes confirmed trainees assigned to the selected batch.</p>
        </section>
    @endif
</div>
