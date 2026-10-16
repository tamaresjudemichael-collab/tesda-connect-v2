<?php

use App\Models\Announcement;
use App\Models\Institution;
use App\Models\User;
use Livewire\Livewire;

function createAnnouncementInstitution(string $code = 'BST'): Institution
{
    return Institution::create([
        'code' => $code,
        'name' => $code === 'BST' ? 'Balli School of Technology' : 'Mamburao Integrated Farm',
        'is_active' => true,
    ]);
}

test('administrator can publish a global announcement with an expiry date', function () {
    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $this->actingAs($admin);

    Livewire::test('announcements.index')
        ->call('openCreate')
        ->set('institution_id', '')
        ->set('title', 'System orientation')
        ->set('body', 'Orientation begins next week.')
        ->set('audience', 'all')
        ->set('published_at', '')
        ->set('expires_at', now()->addDays(7)->format('Y-m-d\TH:i'))
        ->call('saveAnnouncement', 'published')
        ->assertHasNoErrors();

    $announcement = Announcement::query()->where('title', 'System orientation')->firstOrFail();

    expect($announcement->institution_id)->toBeNull()
        ->and($announcement->author_user_id)->toBe($admin->id)
        ->and($announcement->published_at)->not->toBeNull()
        ->and($announcement->expires_at)->not->toBeNull()
        ->and($announcement->archived_at)->toBeNull();
});

test('registrar can schedule an announcement only for an assigned institution', function () {
    $institution = createAnnouncementInstitution('BST');
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);
    $registrar->institutions()->attach($institution->id, ['is_primary' => true]);
    $future = now()->addDays(2)->startOfMinute();

    $this->actingAs($registrar);

    Livewire::test('announcements.index')
        ->call('openCreate')
        ->set('institution_id', (string) $institution->id)
        ->set('title', 'Upcoming assessment')
        ->set('body', 'Please prepare your materials.')
        ->set('audience', 'scholar')
        ->set('published_at', $future->format('Y-m-d\TH:i'))
        ->set('expires_at', $future->copy()->addDays(3)->format('Y-m-d\TH:i'))
        ->call('saveAnnouncement', 'published')
        ->assertHasNoErrors();

    $announcement = Announcement::query()->where('title', 'Upcoming assessment')->firstOrFail();

    expect($announcement->institution_id)->toBe($institution->id)
        ->and($announcement->published_at->isFuture())->toBeTrue()
        ->and($announcement->audience)->toBe('scholar');
});

test('registrar cannot archive an announcement for an unassigned institution', function () {
    $otherInstitution = createAnnouncementInstitution('MIF');
    $assignedInstitution = createAnnouncementInstitution('BST');
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);
    $registrar->institutions()->attach($assignedInstitution->id, ['is_primary' => true]);

    $announcement = Announcement::query()->create([
        'institution_id' => $otherInstitution->id,
        'author_user_id' => $registrar->id,
        'title' => 'Other institution announcement',
        'body' => 'This should not be archived by this registrar.',
        'audience' => 'scholar',
        'published_at' => now(),
    ]);

    $this->actingAs($registrar);

    Livewire::test('announcements.index')
        ->call('archive', $announcement->id)
        ->assertForbidden();

    expect($announcement->fresh()->archived_at)->toBeNull();
});

test('learner portal records a unique view for a published learner announcement', function () {
    $learner = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $announcement = Announcement::query()->create([
        'institution_id' => null,
        'author_user_id' => null,
        'title' => 'Learner safety briefing',
        'body' => 'Please read the safety guidance.',
        'audience' => 'scholar',
        'published_at' => now()->subMinute(),
    ]);

    $this->actingAs($learner);

    Livewire::test('learner.portal')->assertSee('Learner safety briefing');
    Livewire::test('learner.portal')->assertSee('Learner safety briefing');

    expect($announcement->views()->count())->toBe(1)
        ->and($announcement->views()->first()?->user_id)->toBe($learner->id);
});

test('learner portal does not display expired or archived announcements', function () {
    $learner = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    Announcement::query()->create([
        'title' => 'Expired learner message',
        'body' => 'This should no longer be displayed.',
        'audience' => 'scholar',
        'published_at' => now()->subDays(3),
        'expires_at' => now()->subDay(),
    ]);
    Announcement::query()->create([
        'title' => 'Archived learner message',
        'body' => 'This has been archived.',
        'audience' => 'scholar',
        'published_at' => now()->subDay(),
        'archived_at' => now(),
    ]);

    $this->actingAs($learner);

    Livewire::test('learner.portal')
        ->assertDontSee('Expired learner message')
        ->assertDontSee('Archived learner message');
});
