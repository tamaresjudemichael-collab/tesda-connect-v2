<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-neutral-900 antialiased">
        <div class="relative grid min-h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="relative hidden h-full flex-col border-e border-neutral-200 bg-neutral-100 p-10 text-neutral-900 lg:flex">
                <div class="absolute inset-0 bg-neutral-100"></div>

                <a href="{{ route('home') }}" class="relative z-20 flex items-center text-lg font-medium" wire:navigate>
                    <span class="flex h-10 w-10 items-center justify-center rounded-md">
                        <x-app-logo-icon class="me-2 h-7 fill-current text-neutral-900" />
                    </span>
                    {{ config('app.name', 'Laravel') }}
                </a>

                @php
                    [$message, $author] = str(Illuminate\Foundation\Inspiring::quotes()->random())->explode('-');
                @endphp

                <div class="relative z-20 mt-auto">
                    <blockquote class="space-y-2">
                        <flux:heading size="lg" class="!text-neutral-900">&ldquo;{{ trim($message) }}&rdquo;</flux:heading>
                        <footer>
                            <flux:heading class="!text-neutral-700">{{ trim($author) }}</flux:heading>
                        </footer>
                    </blockquote>
                </div>
            </div>

            <div class="w-full bg-white lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 px-4 py-8 sm:w-[350px] sm:px-0">
                    <a href="{{ route('home') }}" class="z-20 flex flex-col items-center gap-2 font-medium lg:hidden" wire:navigate>
                        <span class="flex h-9 w-9 items-center justify-center rounded-md">
                            <x-app-logo-icon class="size-9 fill-current text-neutral-900" />
                        </span>

                        <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                    </a>

                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>