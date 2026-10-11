<?php

use App\Models\ActivityLog;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('System Monitoring');

$activities = computed(fn () => ActivityLog::query()->with('user', 'institution')->latest()->get());
?>

<div class="mx-auto max-w-5xl space-y-6"><div><p class="text-sm font-semibold text-cyan-600">Administration</p><flux:heading size="xl" class="mt-1 text-3xl font-black">System Monitoring</flux:heading><flux:text class="mt-1">Audit activity and system health indicators for the connected institutions.</flux:text></div><div class="grid gap-4 sm:grid-cols-3"><flux:card class="bg-emerald-50 p-5"><p class="text-sm font-bold text-emerald-700">Platform status</p><p class="mt-2 text-2xl font-black text-emerald-800">Online</p></flux:card><flux:card class="bg-blue-50 p-5"><p class="text-sm font-bold text-blue-700">Queue driver</p><p class="mt-2 text-2xl font-black text-blue-800">Database</p></flux:card><flux:card class="bg-violet-50 p-5"><p class="text-sm font-bold text-violet-700">Audit events</p><p class="mt-2 text-2xl font-black text-violet-800">{{ $this->activities->count() }}</p></flux:card></div><flux:card class="bg-white p-6 shadow-sm"><flux:heading size="lg">Recent audit activity</flux:heading><div class="mt-4 divide-y divide-slate-100">@forelse($this->activities as $activity)<div class="flex gap-4 py-4"><span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-500">●</span><div class="min-w-0 flex-1"><p class="font-semibold text-slate-700">{{ $activity->description }}</p><p class="mt-1 text-xs text-slate-400">{{ $activity->event }} · {{ $activity->institution?->code ?? 'Platform' }} · {{ $activity->created_at->diffForHumans() }}</p></div></div>@empty <p class="py-10 text-center text-sm text-slate-400">No audit activity yet.</p>@endforelse</div></flux:card></div>
