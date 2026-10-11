<x-layouts::auth :title="__('Sign in')">
    <flux:card class="border border-white/10 bg-[#102120]/95 p-6 text-white shadow-2xl shadow-black/30 sm:p-10">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" class="text-3xl font-black text-white">Welcome back <span aria-hidden="true">👋</span></flux:heading>
                <flux:text class="mt-2 text-base text-teal-50/55">Sign in to access your TESDAConnect workspace.</flux:text>
            </div>
            <a href="{{ route('home') }}" class="grid size-10 place-items-center rounded-full bg-white/10 text-xl text-white/60 transition hover:bg-white/20 hover:text-white" wire:navigate aria-label="Back to home">×</a>
        </div>

        @if (app()->environment('local'))
            <div class="mt-7 rounded-xl border border-teal-100/15 bg-white/7 px-4 py-3 text-sm text-teal-50/65">🔑 Local demo: <strong class="text-white">admin@tesdaconnect.test / admin123</strong></div>
        @endif

        <x-auth-session-status class="mt-5 text-center" :status="session('status')" />
        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="mt-7 flex flex-col gap-5">
            @csrf
            <flux:input name="email" :label="__('Email address')" :value="old('email')" type="email" required autofocus autocomplete="email" placeholder="you@example.com" class="[--flux-input-background:rgba(255,255,255,.09)] [--flux-input-border-color:rgba(255,255,255,.14)] [--flux-input-color:white]" />
            <div class="relative">
                <flux:input name="password" :label="__('Password')" type="password" required autocomplete="current-password" :placeholder="__('Enter your password')" viewable class="[--flux-input-background:rgba(255,255,255,.09)] [--flux-input-border-color:rgba(255,255,255,.14)] [--flux-input-color:white]" />
                @if (Route::has('password.request'))
                    <flux:link class="mt-2 inline-block text-sm text-teal-300" :href="route('password.request')" wire:navigate>Forgot your password?</flux:link>
                @endif
            </div>
            <flux:checkbox name="remember" :label="__('Remember me on this device')" :checked="old('remember')" />
            <flux:button variant="primary" type="submit" class="mt-2 w-full py-3 text-base" data-test="login-button">Sign in →</flux:button>
        </form>

        <p class="mt-7 text-center text-xs leading-5 text-teal-50/40">Your approved role and institution access are applied automatically after sign-in.</p>
    </flux:card>
</x-layouts::auth>
