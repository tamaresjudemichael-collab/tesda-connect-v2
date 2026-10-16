<?php

use App\Models\User;
use Illuminate\Support\Facades\File;

it('shows dated sqlite backups with individual download links', function () {
    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $directory = storage_path('app/backups');
    File::ensureDirectoryExists($directory);
    $filename = 'tesda-connect-db-20261016-044335-testone.sqlite';
    $path = $directory.DIRECTORY_SEPARATOR.$filename;
    File::put($path, 'test sqlite file bytes');

    try {
        $this->actingAs($admin)
            ->get(route('system.health'))
            ->assertOk()
            ->assertSee('Available backups')
            ->assertSee($filename)
            ->assertSee(route('system.backups.download', ['filename' => $filename]));
    } finally {
        File::delete($path);
    }
});

it('allows an administrator to download a stored sqlite backup', function () {
    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $directory = storage_path('app/backups');
    File::ensureDirectoryExists($directory);
    $filename = 'tesda-connect-db-20261016-044335-testtwo.sqlite';
    $path = $directory.DIRECTORY_SEPARATOR.$filename;
    $contents = 'test sqlite file bytes';
    File::put($path, $contents);

    try {
        $response = $this->actingAs($admin)->get(route('system.backups.download', ['filename' => $filename]));
        $response->assertOk();
        $response->assertDownload($filename);
    } finally {
        File::delete($path);
    }
});

it('denies non-administrators from downloading database backups', function () {
    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);

    $this->actingAs($registrar)
        ->get(route('system.backups.download', ['filename' => 'tesda-connect-db-20261016-044335.sqlite']))
        ->assertForbidden();
});

it('does not allow traversal or downloading files outside the backup naming convention', function () {
    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    $this->actingAs($admin)
        ->get('/system/backups/..%2F..%2F.env/download')
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('system.backups.download', ['filename' => 'laravel.log']))
        ->assertNotFound();
});
