<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-[#031f1d]">
    <head>
        @include('partials.head', ['title' => 'TESDAConnect'])
    </head>
    <body class="min-h-screen bg-[#031f1d] text-white antialiased">
        @php($traineeCount = \App\Models\EnrollmentApplication::count())
        @php($batchCount = \App\Models\TrainingBatch::whereIn('status', ['upcoming', 'in_training'])->count())
        @php($courseCount = \App\Models\Course::where('is_active', true)->count())

        <div class="relative overflow-hidden bg-[radial-gradient(circle_at_78%_16%,rgba(20,184,166,.23),transparent_26%),linear-gradient(135deg,#032a27,#063d3a_48%,#031f1d)]">
            <div class="pointer-events-none absolute inset-0 opacity-20 [background-image:linear-gradient(rgba(94,234,212,.32)_1px,transparent_1px),linear-gradient(90deg,rgba(94,234,212,.32)_1px,transparent_1px)] [background-size:96px_96px]"></div>

            <header class="relative z-10 flex items-center justify-between border-y border-white/10 bg-[#021a18]/85 px-5 py-4 backdrop-blur md:px-8">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-full bg-white text-xs font-black text-[#102f68]">TC</span>
                    <span class="text-xl font-semibold tracking-tight">TESDA<span class="text-teal-400">Connect</span></span>
                </a>
                <div class="flex items-center gap-3">
                    <span class="hidden rounded-full border border-amber-300/30 bg-amber-300/10 px-3 py-1.5 text-xs font-semibold text-amber-200 sm:block">● Local demo ready</span>
                    @auth
                        <flux:button variant="primary" :href="route('dashboard')" wire:navigate>Open dashboard</flux:button>
                    @else
                        <flux:button variant="primary" :href="route('login')" wire:navigate>Sign in <span aria-hidden="true">→</span></flux:button>
                    @endauth
                </div>
            </header>

            <section class="relative z-10 mx-auto grid max-w-7xl gap-12 px-6 py-20 lg:grid-cols-[1.08fr_.92fr] lg:px-10 lg:py-28">
                <div class="max-w-2xl">
                    <p class="mb-7 inline-flex rounded-full border border-teal-300/25 bg-teal-300/10 px-4 py-2 text-xs font-bold tracking-[.16em] text-teal-300">MAMBURAO INTEGRATED FARM</p>
                    <h1 class="text-5xl font-black leading-[.94] tracking-tight sm:text-6xl lg:text-7xl">Streamlining <span class="text-teal-400">TESDA</span><br>Training Operations</h1>
                    <p class="mt-7 max-w-xl text-lg leading-8 text-teal-50/70">A role-based training operations platform for learner intake, schedule coordination, progress monitoring, and report readiness.</p>
                    <div class="mt-9 flex flex-wrap gap-3">
                        @auth
                            <flux:button variant="primary" size="base" class="h-12 px-6 text-base" :href="route('dashboard')" wire:navigate>Open dashboard →</flux:button>
                        @else
                            <flux:button variant="primary" size="base" class="h-12 px-6 text-base" :href="route('login')" wire:navigate>Get started →</flux:button>
                        @endauth
                        <a href="#features" class="rounded-lg border border-white/20 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10">Explore features ↓</a>
                    </div>
                    <div class="mt-12 grid max-w-xl grid-cols-2 gap-5 border-t border-white/10 pt-7 sm:grid-cols-4">
                        <div><p class="text-3xl font-black text-teal-400">{{ $traineeCount }}</p><p class="mt-1 text-[10px] font-bold tracking-wider text-teal-50/50">TRAINEES ENROLLED</p></div>
                        <div><p class="text-3xl font-black text-teal-400">{{ $batchCount }}</p><p class="mt-1 text-[10px] font-bold tracking-wider text-teal-50/50">ACTIVE BATCHES</p></div>
                        <div><p class="text-3xl font-black text-teal-400">{{ $courseCount }}</p><p class="mt-1 text-[10px] font-bold tracking-wider text-teal-50/50">ACTIVE COURSES</p></div>
                        <div><p class="text-3xl font-black text-teal-400">100%</p><p class="mt-1 text-[10px] font-bold tracking-wider text-teal-50/50">ROLE-BASED</p></div>
                    </div>
                </div>

                <div class="self-center rounded-3xl border border-teal-100/15 bg-[#0b2c2b]/90 p-5 shadow-2xl shadow-black/25 backdrop-blur">
                    <div class="mb-6 flex items-center justify-between border-b border-white/10 pb-4 text-xs text-teal-50/50"><span class="font-semibold tracking-widest">TESDACONNECT DECISION ENGINE</span><span class="rounded-full bg-emerald-400/15 px-2 py-1 text-[10px] font-bold text-emerald-300">ONLINE</span></div>
                    <div class="space-y-3">
                        @foreach ([['Sync engine active', 'Local-first data is ready to synchronize.'], ['Double-enrollment rule', 'Prevent duplicate training registration.'], ['Report readiness check', 'Find incomplete records before submission.']] as [$heading, $description])
                            <div class="rounded-2xl border border-white/5 bg-white/7 p-4"><p class="font-semibold text-white">{{ $heading }}</p><p class="mt-1 text-sm text-teal-50/50">{{ $description }}</p><span class="mt-3 inline-block text-xs font-semibold text-teal-300">Ready</span></div>
                        @endforeach
                    </div>
                    <div class="mt-5 grid grid-cols-2 gap-4"><div class="rounded-xl bg-white/8 p-4"><p class="text-xs text-teal-50/50">Record readiness</p><p class="mt-2 text-3xl font-black text-white">{{ $traineeCount ? '78' : '0' }}%</p></div><div class="rounded-xl bg-white/8 p-4"><p class="text-xs text-teal-50/50">Intake validation</p><p class="mt-2 text-3xl font-black text-white">{{ $traineeCount ? '2' : '0' }} flags</p></div></div>
                </div>
            </section>
        </div>

        <section id="features" class="bg-[#0b2151] px-6 py-20 lg:px-10">
            <div class="mx-auto max-w-6xl">
                <div class="mb-10 max-w-2xl"><p class="text-sm font-bold uppercase tracking-[.18em] text-cyan-300">One connected workspace</p><h2 class="mt-3 text-3xl font-black">Built around the TESDA training flow.</h2></div>
                <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ([['▣', 'Trainee Intake', 'Digital learner registration, document tracking, and validation.'], ['◎', 'Decision Support', 'Clear statuses that help staff identify the next action.'], ['◴', 'Report Readiness', 'Know which records need completion before reporting.'], ['□', 'Schedule Coordination', 'Manage batches, orientations, and training schedules.'], ['♙', 'Profiles & Records', 'Keep learner, course, and institutional records organized.'], ['▤', 'Role-Based Access', 'Each user sees only the work assigned to their role.']] as [$icon, $heading, $body])
                        <flux:card class="border-0 bg-white p-6 text-slate-900 shadow-lg shadow-black/10">
                            <span class="grid size-11 place-items-center rounded-xl bg-blue-50 text-lg text-blue-600">{{ $icon }}</span>
                            <flux:heading size="lg" class="mt-6">{{ $heading }}</flux:heading>
                            <flux:text class="mt-3 leading-6 text-slate-500">{{ $body }}</flux:text>
                        </flux:card>
                    @endforeach
                </div>
                @if (app()->environment('local'))
                    <div class="mt-10 rounded-2xl border border-teal-300/20 bg-[#07183e] p-5 text-sm text-blue-100"><strong class="text-teal-300">Local demo:</strong> Sign in using <code class="rounded bg-white/10 px-1.5 py-0.5">admin@tesdaconnect.test</code> / <code class="rounded bg-white/10 px-1.5 py-0.5">admin123</code>. These accounts are seeded only for local/testing use.</div>
                @endif
            </div>
        </section>

        <footer class="border-t border-white/10 bg-[#07183e] px-6 py-6 text-center text-xs text-blue-200/60">TESDAConnect v2 · Balli School of Technology &amp; Mamburao Integrated Farm · AY 2024–2025</footer>
        @fluxScripts
    </body>
</html>
