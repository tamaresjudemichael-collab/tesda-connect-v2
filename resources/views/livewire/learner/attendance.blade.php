<?php

use App\Models\AttendanceRecord;
use App\Models\EnrollmentApplication;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('My Attendance');

$application = computed(fn () => EnrollmentApplication::query()->where('learner_user_id', auth()->id())->latest()->first());
$records = computed(fn () => $this->application?->application_status === 'confirmed' ? AttendanceRecord::query()->where('enrollment_application_id', $this->application->id)->orderByDesc('attendance_date')->take(30)->get() : collect());
$summary = computed(function () {
    if ($this->application?->application_status !== 'confirmed') return ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0];
    $counts = AttendanceRecord::query()->where('enrollment_application_id', $this->application->id)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
    return ['present' => (int) ($counts['present'] ?? 0), 'absent' => (int) ($counts['absent'] ?? 0), 'late' => (int) ($counts['late'] ?? 0), 'total' => (int) $counts->sum()];
});
?>

@php($record = $this->application)
@php($attendanceRecords = $this->records)
@php($attendanceSummary = $this->summary)

<div class="mx-auto max-w-6xl space-y-6 pb-10">
    <section class="rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-900 p-6 text-white shadow-xl sm:p-8"><p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-300">Student / Scholar</p><h1 class="mt-2 text-3xl font-black tracking-tight">My Attendance</h1><p class="mt-2 text-sm text-blue-100">Attendance records saved by authorized training staff.</p></section>
    @if ($record?->application_status === 'confirmed')
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7"><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h2 class="text-xl font-black text-slate-900">Attendance summary</h2><p class="mt-1 text-sm text-slate-500">Your latest 30 attendance records.</p></div><span class="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1.5 text-sm font-bold text-slate-700">{{ $attendanceSummary['total'] }} recorded session(s)</span></div><div class="mt-5 grid grid-cols-3 gap-3"><div class="rounded-xl bg-emerald-50 p-4"><p class="text-xs font-semibold text-emerald-700">Present</p><p class="mt-1 text-2xl font-black text-emerald-800">{{ $attendanceSummary['present'] }}</p></div><div class="rounded-xl bg-rose-50 p-4"><p class="text-xs font-semibold text-rose-700">Absent</p><p class="mt-1 text-2xl font-black text-rose-800">{{ $attendanceSummary['absent'] }}</p></div><div class="rounded-xl bg-amber-50 p-4"><p class="text-xs font-semibold text-amber-700">Late</p><p class="mt-1 text-2xl font-black text-amber-800">{{ $attendanceSummary['late'] }}</p></div></div><div class="mt-5 overflow-x-auto"><table class="min-w-full divide-y divide-slate-100 text-sm"><thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Remarks</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse ($attendanceRecords as $attendance)<tr wire:key="learner-attendance-{{ $attendance->id }}"><td class="px-4 py-3 font-medium text-slate-800">{{ $attendance->attendance_date?->format('M d, Y') }}</td><td class="px-4 py-3"><span class="inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $attendance->status === 'present' ? 'bg-emerald-50 text-emerald-700' : ($attendance->status === 'absent' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">{{ str($attendance->status)->headline() }}</span></td><td class="px-4 py-3 text-slate-600">{{ $attendance->remarks ?: '—' }}</td></tr>@empty<tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">No attendance records have been saved yet.</td></tr>@endforelse</tbody></table></div></section>
    @else
        <section class="rounded-3xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm"><h2 class="text-xl font-black text-slate-900">Attendance is not available yet</h2><p class="mt-2 text-sm text-slate-500">This page becomes available after your enrollment is confirmed.</p></section>
    @endif
</div>
