<?php

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Institution;
use App\Models\User;
use Livewire\Livewire;

test('administrator institution edits record field-level from and to values', function () {
    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);
    $institution = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test('system.institutions')
        ->call('edit', $institution->id)
        ->set('name', 'BST Training Institute')
        ->call('save')
        ->assertHasNoErrors();

    $activity = ActivityLog::query()
        ->where('event', 'institution_updated')
        ->latest('id')
        ->firstOrFail();

    expect($activity->metadata['changes']['name'])->toBe([
        'from' => 'Balli School of Technology',
        'to' => 'BST Training Institute',
    ]);
});

test('announcement edits record changed content and publish fields', function () {
    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);
    $announcement = Announcement::query()->create([
        'institution_id' => null,
        'author_user_id' => $admin->id,
        'title' => 'Old announcement title',
        'body' => 'Original announcement details.',
        'audience' => 'scholar',
        'published_at' => now()->subMinute(),
    ]);

    $this->actingAs($admin);

    Livewire::test('announcements.index')
        ->call('edit', $announcement->id)
        ->set('title', 'New announcement title')
        ->call('saveAnnouncement', 'published')
        ->assertHasNoErrors();

    $activity = ActivityLog::query()
        ->where('event', 'announcement_updated')
        ->latest('id')
        ->firstOrFail();

    expect($activity->metadata['changes']['title'])->toBe([
        'from' => 'Old announcement title',
        'to' => 'New announcement title',
    ]);
});
