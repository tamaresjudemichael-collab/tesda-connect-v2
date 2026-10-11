<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="p-4 sm:p-6 lg:p-7">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
