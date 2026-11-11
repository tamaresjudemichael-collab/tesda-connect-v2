<?php

use App\Models\Announcement;
use App\Models\AnnouncementView;
use App\Models\EnrollmentApplication;
use Flux\Flux;
use function Livewire\Volt\{computed, layout, mount, title};

layout('layouts::app');
title('Announcements');

$application = computed(fn () => EnrollmentApplication::query()
    ->where('learner_user_id', auth()->id())
    ->latest()
    ->first());

$visibleAnnouncements = computed(function () {
    $application = $this->application;

    return Announcement::query()
        ->with(['views' => fn ($views) => $views->where('user_id', auth()->id())])
        ->whereNotNull('published_at')
        ->where('published_at', '<=', now())
        ->whereNull('archived_at')
        ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
        ->where(fn ($query) => $query->where('audience', 'all')->orWhere('audience', 'scholar'))
        ->where(function ($query) use ($application): void {
            $query->whereNull('institution_id');

            if ($application?->institution_id) {
                $query->orWhere('institution_id', $application->institution_id);
            }
        })
        ->latest('published_at')
        ->get();
});

mount(function (): void {
    foreach ($this->visibleAnnouncements as $announcement) {
        AnnouncementView::query()->firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => auth()->id()],
            ['viewed_at' => now(), 'read_at' => null],
        );
    }
});

$markRead = function (int $announcementId): void {
    $announcement = $this->visibleAnnouncements->firstWhere('id', $announcementId);

    abort_unless($announcement, 404, 'This announcement is not available to your account.');

    $view = AnnouncementView::query()->firstOrCreate(
        ['announcement_id' => $announcement->id, 'user_id' => auth()->id()],
        ['viewed_at' => now(), 'read_at' => null],
    );

    if ($view->read_at === null) {
        $view->forceFill(['read_at' => now()])->save();
    }

    unset($this->visibleAnnouncements);
    Flux::toast(variant: 'success', text: 'Announcement marked as read.');
};
?>

@php($announcements = $this->visibleAnnouncements)
@php($unread = $announcements->filter(fn ($announcement) => $announcement->views->isEmpty() || $announcement->views->first()?->read_at === null)->count())

<div class="mx-auto max-w-5xl space-y-6 pb-10">
    <section class="rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-900 p-6 text-white shadow-xl sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-300">Student / Scholar</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight">Announcements</h1>
        <p class="mt-2 text-sm text-blue-100">Updates published for learners and scholars.</p>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-black text-slate-900">All announcements</h2>
            <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $unread > 0 ? 'bg-blue-50 text-blue-800' : 'bg-slate-100 text-slate-600' }}">{{ $unread }} unread</span>
        </div>

        <div class="mt-5 space-y-3">
            @forelse ($announcements as $announcement)
                @php($isRead = $announcement->views->contains(fn ($view) => $view->read_at !== null))
                <article class="rounded-2xl border p-5 {{ $isRead ? 'border-slate-100 bg-white' : 'border-blue-200 bg-blue-50/40' }}" wire:key="learner-announcement-{{ $announcement->id }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <h3 class="font-bold text-slate-900">{{ $announcement->title }}</h3>
                            <p class="mt-1 text-xs text-slate-500">Published {{ $announcement->published_at?->format('M d, Y h:i A') }} @if ($announcement->deadline_at) · Submission deadline {{ $announcement->deadline_at->format('M d, Y h:i A') }} @endif</p>
                            <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $announcement->body }}</p>
                            @if ($announcement->attachment_original_name)
                                <a href="{{ route('announcements.attachment', $announcement->id) }}" class="mt-3 inline-flex text-sm font-bold text-blue-700 hover:text-cyan-700">Download attachment</a>
                            @endif
                        </div>
                        @unless ($isRead)
                            <button type="button" wire:click="markRead({{ $announcement->id }})" class="inline-flex shrink-0 rounded-lg bg-blue-700 px-3 py-2 text-sm font-bold text-white hover:bg-blue-800">Mark as read</button>
                        @endunless
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-200 px-5 py-8 text-center">
                    <p class="font-semibold text-slate-700">No announcements yet</p>
                    <p class="mt-1 text-sm text-slate-500">New updates will appear here.</p>
                </div>
            @endforelse
        </div>
    </section>
</div>
