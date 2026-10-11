<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
        @php($user = auth()->user())

        <flux:sidebar sticky collapsible="mobile" class="border-e-0 bg-[#102f68] text-white shadow-xl shadow-slate-900/10">
            <flux:sidebar.header class="border-b border-white/10 px-5 py-5">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3" wire:navigate>
                    <span class="grid size-10 place-items-center rounded-full bg-white text-sm font-black text-[#102f68]">TC</span>
                    <span class="grid gap-0.5 leading-tight">
                        <span class="font-bold tracking-tight text-white">TESDA<span class="text-[#f6a915]">Connect</span></span>
                        <span class="text-[11px] text-blue-200">Training Operations</span>
                    </span>
                </a>
                <flux:sidebar.collapse class="text-white lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav class="px-3 py-4">
                <flux:sidebar.group heading="MAIN MENU" class="grid text-blue-200">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        Dashboard
                    </flux:sidebar.item>

                    @if ($user->hasRole('administrator', 'registrar'))
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('enrollments.index')" :current="request()->routeIs('enrollments.*')" wire:navigate>
                            Enrollment Monitoring
                        </flux:sidebar.item>
                    @endif

                    @if ($user->hasRole('administrator', 'registrar', 'focal_person'))
                        <flux:sidebar.item icon="calendar-days" :href="route('schedules.index')" :current="request()->routeIs('schedules.*')" wire:navigate>
                            Scheduling Management
                        </flux:sidebar.item>
                    @endif

                    @if ($user->hasRole('administrator', 'focal_person'))
                        <flux:sidebar.item icon="academic-cap" :href="route('progress.index')" :current="request()->routeIs('progress.*')" wire:navigate>
                            Progress & Achievement
                        </flux:sidebar.item>
                    @endif

                    @if ($user->hasRole('administrator', 'registrar', 'focal_person'))
                        <flux:sidebar.item icon="chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>
                            Reports & Oversight
                        </flux:sidebar.item>
                    @endif

                    @if ($user->hasRole('administrator'))
                        <flux:sidebar.item icon="users" :href="route('system.accounts')" :current="request()->routeIs('system.accounts')" wire:navigate>
                            User Accounts
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="shield-check" :href="route('system.activity')" :current="request()->routeIs('system.activity')" wire:navigate>
                            System Monitoring
                        </flux:sidebar.item>
                    @endif

                  
                    @if ($user->hasRole('scholar'))
                        <flux:sidebar.item
                            icon="clipboard-document-list"
                            :href="route('learner.registration')"
                            :current="request()->routeIs('learner.registration')"
                            wire:navigate
                        >
                            Registration Form
                        </flux:sidebar.item>

                        <flux:sidebar.item
                            icon="academic-cap"
                            :href="route('learner.portal')"
                            :current="request()->routeIs('learner.portal')"
                            wire:navigate
                        >
                            My Learning Portal
                        </flux:sidebar.item>
                    @endif

                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <div class="border-t border-white/10 p-4">
                <div class="flex items-center gap-3 rounded-xl bg-white/8 p-3">
                    <flux:avatar :name="$user->name" :initials="$user->initials()" class="bg-cyan-400 text-[#102f68]" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">{{ $user->name }}</p>
                        <p class="truncate text-xs text-blue-200">{{ $user->roleLabel() }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-lg border border-white/15 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/10">
                        <flux:icon icon="arrow-right-start-on-rectangle" class="size-4" />
                        Sign out
                    </button>
                </form>
            </div>
        </flux:sidebar>

        <flux:header class="border-b border-slate-200 bg-white px-4 shadow-sm lg:px-7">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-3" inset="left" />
            <div class="hidden items-center gap-2 text-sm text-slate-500 md:flex">
                <span>TESDAConnect</span>
                <span class="text-slate-300">/</span>
                <span class="font-semibold text-slate-700">{{ $title ?? 'Dashboard' }}</span>
            </div>
            <flux:spacer />
            <div class="hidden items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 sm:flex">
                <span class="size-2 rounded-full bg-emerald-500"></span>
                Online
            </div>
            <a href="{{ route('profile.edit') }}" class="ml-3 flex items-center gap-2 rounded-xl p-1.5 transition hover:bg-slate-100" wire:navigate>
                <flux:avatar :name="$user->name" :initials="$user->initials()" class="bg-cyan-500 text-white" />
                <span class="hidden text-left sm:grid">
                    <span class="text-sm font-semibold leading-tight">{{ $user->name }}</span>
                    <span class="text-xs text-slate-500">{{ $user->roleLabel() }}</span>
                </span>
            </a>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
