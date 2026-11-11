<x-layouts::auth :title="__('Register')">
    <flux:card class="register-card border border-white/15 bg-[#102120]/95 p-6 !text-white shadow-2xl shadow-black/30 sm:p-8">
        <div class="flex flex-col gap-6">
            <x-auth-header
                :title="__('Create an account')"
                :description="__('Enter your details below to create your account')"
            />

            <x-auth-session-status
                class="text-center text-sm !text-white"
                :status="session('status')"
            />

            <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-5">
                @csrf

                <flux:select
                    name="institution_id"
                    :label="__('Institution')"
                    :value="old('institution_id')"
                    :placeholder="__('Select your institution')"
                    required
                >
                    @foreach ($institutions as $institution)
                        <flux:select.option :value="$institution->id">
                            {{ $institution->name }} ({{ $institution->code }})
                        </flux:select.option>
                    @endforeach
                </flux:select>
                @error('institution_id')
                    <p class="-mt-3 text-sm font-medium text-rose-300">{{ $message }}</p>
                @enderror

                <flux:input
                    name="name"
                    :label="__('Name')"
                    :value="old('name')"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    :placeholder="__('Full name')"
                />

                <flux:input
                    name="email"
                    :label="__('Email address')"
                    :value="old('email')"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="email@example.com"
                />

                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="__('Password')"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                />

                <flux:input
                    name="password_confirmation"
                    :label="__('Confirm password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="__('Confirm password')"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                />

                <flux:button type="submit" variant="primary" class="w-full py-3 text-base font-bold" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </form>

            <div class="text-center text-sm text-white/80">
                <span>{{ __('Already have an account?') }}</span>
                <flux:link
                    :href="route('login')"
                    wire:navigate
                    class="font-semibold !text-teal-300 hover:!text-teal-200"
                >
                    {{ __('Log in') }}
                </flux:link>
            </div>
        </div>
    </flux:card>
</x-layouts::auth>

<style>
    .register-card [data-flux-label],
    .register-card [data-flux-label] *,
    .register-card label,
    .register-card label span {
        color: #f8fafc !important;
        opacity: 1 !important;
    }

    .register-card input[data-flux-control],
    .register-card textarea[data-flux-control],
    .register-card select[data-flux-control],
    .register-card button[data-flux-control] {
        color: #111827 !important;
        background-color: #ffffff !important;
        border-color: #cbd5e1 !important;
    }

    .register-card input[data-flux-control]::placeholder,
    .register-card textarea[data-flux-control]::placeholder {
        color: #64748b !important;
        opacity: 1 !important;
    }

    .register-card [data-flux-description],
    .register-card .text-zinc-500,
    .register-card .text-zinc-600 {
        color: #cbd5e1 !important;
    }
</style>
