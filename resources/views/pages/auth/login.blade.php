<x-layouts::auth :title="__('Sign in')">
    <flux:card class="login-card border border-white/15 bg-[#102120]/95 p-6 !text-white shadow-2xl shadow-black/30 sm:p-10">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" class="text-3xl font-black tracking-tight !text-white">
                    Welcome back <span aria-hidden="true">👋</span>
                </flux:heading>

                <flux:text class="mt-2 text-base leading-6 !text-white/90">
                    Sign in to access your TESDA Connect workspace.
                </flux:text>
            </div>

            <a
                href="{{ route('home') }}"
                class="grid size-10 shrink-0 place-items-center rounded-full bg-white/10 text-xl text-white/80 transition hover:bg-white/20 hover:text-white"
                wire:navigate
                aria-label="Back to home"
            >
                ×
            </a>
        </div>

        <x-auth-session-status
            class="mt-5 text-center text-sm !text-white"
            :status="session('status')"
        />

        <form method="POST" action="{{ route('login.store') }}" class="mt-7 flex flex-col gap-5">
            @csrf

                        <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email', app()->environment('local') ? 'admin@tesdaconnect.test' : '')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="you@example.com"
class="[--flux-input-background:#ffffff] [--flux-input-border-color:#d1d5db] [--flux-input-color:#111827] [--flux-input-placeholder-color:#6b7280] [&_.\[\:where\(\&\)\]\:text-zinc-800]:!text-white [&_[data-flux-label]]:!text-white [&_label]:!text-white [&_label]:!opacity-100"            />

            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :value="app()->environment('local') ? 'admin123' : ''"
                    :placeholder="__('Enter your password')"
                    viewable
class="[--flux-input-background:#ffffff] [--flux-input-border-color:#d1d5db] [--flux-input-color:#111827] [--flux-input-placeholder-color:#6b7280] [&_.\[\:where\(\&\)\]\:text-zinc-800]:!text-white [&_[data-flux-label]]:!text-white [&_label]:!text-white [&_label]:!opacity-100"                />

                @if (Route::has('password.request'))
                    <flux:link
                        class="mt-2 inline-block text-sm font-semibold !text-teal-300 hover:!text-teal-200"
                        :href="route('password.request')"
                        wire:navigate
                    >
                        Forgot your password?
                    </flux:link>
                @endif
            </div>

            <div class="rounded-lg border border-white/15 bg-white/5 px-3 py-3">
                <flux:checkbox
                    name="remember"
                    :label="__('Remember me on this device')"
                    :checked="old('remember')"
class="[--flux-checkbox-label-color:#ffffff] [&_.\[\:where\(\&\)\]\:text-zinc-800]:!text-white [&_[data-flux-label]]:!text-white [&_label]:!text-white [&_label]:!opacity-100"                />
            </div>

            <flux:button
                variant="primary"
                type="submit"
                class="mt-2 w-full py-3 text-base font-bold"
                data-test="login-button"
            >
                Sign in →
            </flux:button>
        </form>

        <p class="mt-7 text-center text-xs leading-5 !text-white/80">
            Your approved role and institution access are applied automatically after sign-in.
        </p>

        <div class="mt-6 border-t border-white/15 pt-6">
            <p class="mb-4 text-center text-xs font-semibold uppercase tracking-wider !text-white/60">
                Or sign in another way
            </p>

            <x-passkey-verify />
        </div>
    </flux:card>
</x-layouts::auth>

<style>
    .login-card [data-flux-label],
.login-card [data-flux-label] *,
.login-card label,
.login-card label span {
    color: #ffffff !important;
    opacity: 1 !important;
}

.login-card input[data-flux-control],
.login-card textarea[data-flux-control],
.login-card select[data-flux-control] {
    color: #111827 !important;
    background-color: #ffffff !important;
}

.login-card input[data-flux-control]::placeholder,
.login-card textarea[data-flux-control]::placeholder {
    color: #6b7280 !important;
    opacity: 1 !important;
}

.login-card [data-flux-checkbox] {
    color: #ffffff !important;
}

.login-card [data-flux-checkbox] label,
.login-card [data-flux-checkbox] label span {
    color: #ffffff !important;
}
</style>