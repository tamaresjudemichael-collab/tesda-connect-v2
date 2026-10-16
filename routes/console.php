<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Artisan::command('accounts:lock-inactive-staff', function (): int {
    $cutoff = now()->subDays(90);
    $accounts = User::query()->with('institutions')
        ->whereIn('role', ['registrar', 'focal_person'])
        ->where('account_status', 'active')
        ->where(fn ($query) => $query->where('last_login_at', '<=', $cutoff)
            ->orWhere(fn ($never) => $never->whereNull('last_login_at')->where('created_at', '<=', $cutoff)))
        ->get();

    foreach ($accounts as $account) {
        $account->forceFill(['account_status' => 'inactive'])->save();
        ActivityLogger::record(
            'staff_account_auto_locked',
            'Automatically deactivated staff account after 90 days without a recorded login.',
            (int) $account->institutions->first()?->id,
            ['account_id' => $account->id, 'inactivity_days' => 90, 'last_login_at' => $account->last_login_at?->toIso8601String()]
        );
    }

    $this->info($accounts->count().' staff account(s) deactivated.');
    return self::SUCCESS;
})->purpose('Deactivate Registrar and Focal Person accounts inactive for 90 days.');

Schedule::command('accounts:lock-inactive-staff')->dailyAt('02:00')->withoutOverlapping();
