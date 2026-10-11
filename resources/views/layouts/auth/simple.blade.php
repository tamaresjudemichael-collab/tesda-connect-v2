<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-[#061312]">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[radial-gradient(circle_at_50%_0%,rgba(13,148,136,.22),transparent_32%),linear-gradient(135deg,#061312,#031f1d)] antialiased">
        <div class="pointer-events-none fixed inset-0 opacity-20 [background-image:linear-gradient(rgba(94,234,212,.18)_1px,transparent_1px),linear-gradient(90deg,rgba(94,234,212,.18)_1px,transparent_1px)] [background-size:80px_80px]"></div>
        <main class="relative grid min-h-svh place-items-center p-5 sm:p-8">
            <div class="w-full max-w-xl">
                <a href="{{ route('home') }}" class="mb-6 flex items-center justify-center gap-3 text-white" wire:navigate>
                    <span class="grid size-11 place-items-center rounded-full bg-white text-xs font-black text-[#102f68]">TC</span>
                    <span class="text-xl font-semibold">TESDA<span class="text-teal-400">Connect</span></span>
                </a>
                {{ $slot }}
            </div>
        </main>

        @persist('toast')
            <flux:toast.group><flux:toast /></flux:toast.group>
        @endpersist
        @fluxScripts
    </body>
</html>
