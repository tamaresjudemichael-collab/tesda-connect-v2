<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#0f766e">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<script defer src="{{ asset('pwa-register.js') }}" data-worker-url="{{ asset('service-worker.js') }}" data-worker-scope="{{ url('/') }}"></script>
<script defer src="{{ asset('offline-competency-sync.js') }}" data-sync-url="{{ route('offline-sync.competency-progress') }}" data-user-id="{{ auth()->id() ?? '' }}"></script>

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    document.documentElement.classList.remove('dark');
    document.documentElement.classList.add('light');
    localStorage.setItem('flux.appearance', 'light');
</script>
@fluxAppearance
