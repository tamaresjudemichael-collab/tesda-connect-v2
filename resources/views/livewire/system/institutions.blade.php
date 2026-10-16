
<?php

use App\Models\Institution;
use App\Support\ActivityLogger;
use Flux\Flux;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Institution Management');

state([
    'formOpen' => false,
    'editingId' => null,
    'code' => '',
    'name' => '',
    'address' => '',
    'contact_email' => '',
    'contact_number' => '',
    'is_active' => '1',
]);

$institutions = computed(fn () => Institution::query()
    ->withCount(['users', 'courses', 'batches'])
    ->orderBy('name')
    ->get());

$resetForm = function (): void {
    $this->reset(
        'editingId',
        'code',
        'name',
        'address',
        'contact_email',
        'contact_number',
        'is_active'
    );

    $this->is_active = '1';
    $this->resetValidation();
};

$openCreate = function (): void {
    $this->resetForm();
    $this->formOpen = true;
};

$edit = function (int $id): void {
    $institution = Institution::findOrFail($id);

    $this->resetValidation();
    $this->editingId = $institution->id;
    $this->code = $institution->code;
    $this->name = $institution->name;
    $this->address = $institution->address ?? '';
    $this->contact_email = $institution->contact_email ?? '';
    $this->contact_number = $institution->contact_number ?? '';
    $this->is_active = $institution->is_active ? '1' : '0';
    $this->formOpen = true;
};

$save = function (): void {
    $this->code = strtoupper(trim($this->code));

    $validated = $this->validate([
        'code' => [
            'required',
            'string',
            'max:10',
            'regex:/^[A-Z0-9-]{2,10}$/',
            Rule::unique('institutions', 'code')->ignore($this->editingId),
        ],
        'name' => ['required', 'string', 'max:255'],
        'address' => ['nullable', 'string', 'max:2000'],
        'contact_email' => ['nullable', 'email', 'max:255'],
        'contact_number' => ['nullable', 'string', 'max:30'],
        'is_active' => ['required', Rule::in(['1', '0'])],
    ]);

    $attributes = [
        'code' => $validated['code'],
        'name' => trim($validated['name']),
        'address' => $validated['address'] ?: null,
        'contact_email' => $validated['contact_email'] ?: null,
        'contact_number' => $validated['contact_number'] ?: null,
        'is_active' => $validated['is_active'] === '1',
    ];

$wasEditing = filled($this->editingId);

$before = [];
if ($wasEditing) {
    $institution = Institution::findOrFail($this->editingId);
    $before = ActivityLogger::snapshot($institution, ['code', 'name', 'address', 'contact_email', 'contact_number', 'is_active']);
    $institution->update($attributes);
    $changes = ActivityLogger::changes($institution, $before);
} else {
    $institution = Institution::create($attributes);
    $changes = [];
}

ActivityLogger::record(
    $wasEditing ? 'institution_updated' : 'institution_created',
    ($wasEditing ? 'Updated' : 'Created').' institution '.$institution->name,
    (int) $institution->id,
    [
        'institution_id' => $institution->id,
        'code' => $institution->code,
        'changes' => $changes,
    ]
);

    unset($this->institutions);

    $this->resetForm();
    $this->formOpen = false;

    Flux::toast(
        variant: 'success',
       text: $wasEditing
    ? 'Institution updated successfully.'
    : 'Institution saved successfully.'
    );
};

$toggleStatus = function (int $id): void {
    $institution = Institution::findOrFail($id);
    $before = ActivityLogger::snapshot($institution, ['is_active']);
    $institution->update([
        'is_active' => ! $institution->is_active,
    ]);

    ActivityLogger::record(
    $institution->is_active ? 'institution_activated' : 'institution_deactivated',
    ($institution->is_active ? 'Activated' : 'Deactivated').' institution '.$institution->name,
    (int) $institution->id,
    [
        'institution_id' => $institution->id,
        'code' => $institution->code,
        'is_active' => (bool) $institution->is_active,
        'changes' => ActivityLogger::changes($institution, $before),
    ]
);

    unset($this->institutions);

    Flux::toast(
        variant: 'success',
        text: $institution->is_active
            ? 'Institution activated.'
            : 'Institution deactivated.'
    );
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-cyan-600">Administration</p>
            <flux:heading size="xl" class="mt-1 text-3xl font-black">
                Institution Management
            </flux:heading>
            <flux:text class="mt-1">
                Manage training institutions, contact information, and access availability.
            </flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreate">
            Add institution
        </flux:button>
    </div>

    @if ($formOpen)
        <flux:card class="bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading size="lg">
                        {{ $editingId ? 'Edit institution' : 'Add institution' }}
                    </flux:heading>
                    <flux:text class="mt-1">
                        Enter the institution information below.
                    </flux:text>
                </div>

                <flux:button
                    variant="ghost"
                    icon="x-mark"
                    wire:click="$set('formOpen', false)"
                    aria-label="Close form"
                />
            </div>

            <form wire:submit="save" class="mt-6 space-y-5">
                <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    <flux:input
                        wire:model="code"
                        label="Institution code"
                        placeholder="e.g. BST"
                        required
                    />

                    <flux:input
                        wire:model="name"
                        label="Institution name"
                        required
                    />

                    <flux:input
                        wire:model="contact_email"
                        label="Contact email"
                        type="email"
                    />

                    <flux:input
                        wire:model="contact_number"
                        label="Contact number"
                    />

                    <flux:select wire:model="is_active" label="Status" required>
                        <flux:select.option value="1">Active</flux:select.option>
                        <flux:select.option value="0">Inactive</flux:select.option>
                    </flux:select>
                </div>

                <flux:textarea
                    wire:model="address"
                    label="Institution address"
                    rows="3"
                />

                <div class="flex flex-wrap justify-end gap-3">
                    <flux:button
                        variant="ghost"
                        type="button"
                        wire:click="resetForm"
                    >
                        Reset
                    </flux:button>

                    <flux:button variant="primary" type="submit">
                        {{ $editingId ? 'Save changes' : 'Save institution' }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    <flux:card class="bg-white p-5 shadow-sm">
        <div class="mb-5">
            <flux:heading size="lg">Registered institutions</flux:heading>
            <flux:text class="mt-1">
                Review institution details, linked records, and status.
            </flux:text>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400">
                    <tr>
                        <th class="px-3 py-3">Institution</th>
                        <th class="px-3 py-3">Contact information</th>
                        <th class="px-3 py-3">Linked records</th>
                        <th class="px-3 py-3">Status</th>
                        <th class="px-3 py-3 text-right">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->institutions as $institution)
                        <tr wire:key="institution-row-{{ $institution->id }}">
                            <td class="px-3 py-4">
                                <p class="font-bold text-slate-900">
                                    {{ $institution->name }}
                                </p>
                                <p class="mt-1 text-xs font-semibold text-cyan-700">
                                    {{ $institution->code }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $institution->address ?: 'No address provided' }}
                                </p>
                            </td>

                            <td class="px-3 py-4 text-slate-600">
                                <p>{{ $institution->contact_email ?: 'No email provided' }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $institution->contact_number ?: 'No contact number' }}
                                </p>
                            </td>

                            <td class="px-3 py-4 text-slate-600">
                                <p>{{ $institution->users_count }} users</p>
                                <p class="mt-1">{{ $institution->courses_count }} courses</p>
                                <p class="mt-1">{{ $institution->batches_count }} batches</p>
                            </td>

                            <td class="px-3 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $institution->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $institution->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>

                            <td class="px-3 py-4">
                                <div class="flex justify-end gap-2">
                                    <flux:button
                                        size="sm"
                                        variant="filled"
                                        wire:click="edit({{ $institution->id }})"
                                    >
                                        Edit
                                    </flux:button>

                                    <flux:button
                                        size="sm"
                                        variant="{{ $institution->is_active ? 'danger' : 'primary' }}"
                                        wire:click="toggleStatus({{ $institution->id }})"
                                        wire:confirm="Change this institution's active status?"
                                    >
                                        {{ $institution->is_active ? 'Deactivate' : 'Activate' }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-12 text-center text-slate-400">
                                No institutions have been added.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
