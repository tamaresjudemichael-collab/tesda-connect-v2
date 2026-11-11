<?php

use App\Models\LearnerDocument;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('My Documents');

$documents = computed(fn () => LearnerDocument::query()
    ->where('user_id', auth()->id())
    ->latest()
    ->get());
?>

@php($documents = $this->documents)

<div class="mx-auto max-w-5xl space-y-6 pb-10">
    <section class="rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-900 p-6 text-white shadow-xl sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-300">Student / Scholar</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight">My Documents</h1>
        <p class="mt-2 text-sm text-blue-100">Review the status of your submitted requirements and update them when needed.</p>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-black text-slate-900">Submitted requirements</h2>
                <p class="mt-1 text-sm text-slate-500">Only you and authorized staff can access private documents.</p>
            </div>
            <a href="{{ route('learner.registration') }}" wire:navigate class="inline-flex w-fit rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800">Upload or update documents</a>
        </div>

        <div class="mt-5 space-y-3">
            @forelse ($documents as $document)
                <article class="flex flex-col gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="break-words font-bold text-slate-800">{{ $document->original_name }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ str($document->document_type)->headline() }} · {{ number_format($document->size / 1024, 0) }} KB</p>
                        @if ($document->status === 'rejected' && filled($document->remarks))
                            <p class="mt-2 text-sm text-rose-700"><span class="font-semibold">Reason for rejection:</span> {{ $document->remarks }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col items-start gap-2 sm:flex-row sm:items-center">
                        <span class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold {{ $document->status === 'verified' ? 'bg-emerald-50 text-emerald-700' : ($document->status === 'rejected' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">{{ str($document->status)->headline() }}</span>
                        <a href="{{ route('learner-documents.download', $document->id) }}" class="inline-flex w-fit rounded-lg bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100">Download</a>
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-200 px-5 py-8 text-center">
                    <p class="font-semibold text-slate-700">No documents uploaded yet</p>
                    <p class="mt-1 text-sm text-slate-500">Your submitted requirements will appear here.</p>
                </div>
            @endforelse
        </div>
    </section>
</div>
