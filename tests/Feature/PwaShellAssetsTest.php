<?php

use Illuminate\Support\Facades\File;

test('public PWA shell assets are present and the main layout links to them', function () {
    expect(File::exists(public_path('manifest.webmanifest')))->toBeTrue()
        ->and(File::exists(public_path('service-worker.js')))->toBeTrue()
        ->and(File::exists(public_path('offline.html')))->toBeTrue()
        ->and(File::exists(public_path('pwa-icon-192.png')))->toBeTrue()
        ->and(File::exists(public_path('pwa-icon-512.png')))->toBeTrue()
        ->and(File::exists(public_path('pwa-register.js')))->toBeTrue();

    $head = File::get(resource_path('views/partials/head.blade.php'));

    expect($head)->toContain("manifest.webmanifest")
        ->toContain("pwa-register.js")
        ->toContain("service-worker.js");
});

test('service worker does not declare sensitive learner data or app pages as shell cache entries', function () {
    $worker = File::get(public_path('service-worker.js'));

    expect($worker)->toContain('Do not cache authenticated HTML')
        ->toContain('learner-documents')
        ->toContain("request.mode === 'navigate'")
        ->not->toContain("'dashboard'")
        ->not->toContain("'my-learning'");
});
