<?php

use App\Models\EnrollmentBatchForwarding;
use App\Support\InstitutionScope;
use function Livewire\Volt\{computed, layout, title, usesPagination};

usesPagination();

layout('layouts::app');
title('Forwarded Enrollment Batches');

$forwardings = computed(fn () => EnrollmentBatchForwarding::query()
    ->with(['institution', 'course', 'trainingBatch', 'forwardedBy'])
    ->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()))
    ->latest('forwarded_at')->paginate(15));
?>

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <div><p class="text-sm font-semibold text-blue-700">Enrollment Monitoring</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Forwarded Batches</flux:heading><flux:text class="mt-1">Confirmed enrollment groups sent by the Registrar and marked Ready for Scheduling.</flux:text></div>
    <flux:card class="bg-white p-0 shadow-sm">
        <div class="overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Reference</th><th class="px-5 py-3">Institution / Course</th><th class="px-5 py-3">Batch</th><th class="px-5 py-3">Records</th><th class="px-5 py-3">Forwarded</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Summary</th></tr></thead><tbody class="divide-y divide-slate-100">
        @forelse ($this->forwardings as $forwarding)<tr wire:key="forwarding-{{ $forwarding->id }}"><td class="px-5 py-4 font-semibold">{{ $forwarding->reference_code }}</td><td class="px-5 py-4"><p class="font-semibold">{{ $forwarding->institution->code }} — {{ $forwarding->institution->name }}</p><p class="text-xs text-slate-500">{{ $forwarding->course->name }}</p></td><td class="px-5 py-4">{{ $forwarding->trainingBatch?->name ?? 'General intake' }}</td><td class="px-5 py-4">{{ $forwarding->application_count }}</td><td class="px-5 py-4">{{ $forwarding->forwarded_at?->format('M j, Y g:i A') }}</td><td class="px-5 py-4"><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">{{ str($forwarding->status)->headline() }}</span></td><td class="px-5 py-4"><a class="font-semibold text-blue-700 underline" href="{{ route('enrollment-forwardings.summary', $forwarding) }}">Download CSV</a></td></tr>
        @empty<tr><td colspan="7" class="px-5 py-12 text-center text-slate-500">No batches have been forwarded yet.</td></tr>@endforelse
        </tbody></table></div><div class="p-4">{{ $this->forwardings->links() }}</div>
    </flux:card>
</div>
