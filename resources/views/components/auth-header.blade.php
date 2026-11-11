@props([
    'title',
    'description',
])

<div class="flex w-full flex-col text-center">
    <flux:heading size="xl" level="1" class="!text-white">{{ $title }}</flux:heading>
    <flux:subheading class="!text-white/75">{{ $description }}</flux:subheading>
</div>
