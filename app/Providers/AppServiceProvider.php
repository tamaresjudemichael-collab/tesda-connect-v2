<?php

namespace App\Providers;

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureRole;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Auth\Events\Logout as LogoutEvent;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
public function boot(): void
{
    $this->configureDefaults();

    Livewire::addPersistentMiddleware([
        EnsureActiveAccount::class,
        EnsureRole::class,
    ]);

    Event::listen(Login::class, function (Login $event): void {
        $user = $event->user;

        if (! ($user instanceof User)) {
            return;
        }

        $institutionId = $user->institutions()
            ->wherePivot('is_primary', true)
            ->value('institutions.id');

        $user->forceFill(['last_login_at' => now()])->save();

        ActivityLogger::record(
            'user_logged_in',
            "{$user->name} logged in.",
            $institutionId
        );
    });

    Event::listen(LogoutEvent::class, function (LogoutEvent $event): void {
        $user = $event->user;

        if (! ($user instanceof User)) {
            return;
        }

        $institutionId = $user->institutions()
            ->wherePivot('is_primary', true)
            ->value('institutions.id');

        ActivityLogger::record(
            'user_logged_out',
            "{$user->name} logged out.",
            $institutionId
        );
    });
}

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
