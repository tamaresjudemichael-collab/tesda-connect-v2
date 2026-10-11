
<?php

use App\Models\Institution;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('User Accounts');

state([
    'formOpen' => false,
    'editingId' => null,
    'name' => '',
    'username' => '',
    'email' => '',
    'password' => '',
    'role' => 'scholar',
    'account_status' => 'active',
    'institutionId' => null,
]);

$accounts = computed(fn () => User::query()->with('institutions')->orderBy('role')->orderBy('name')->get());

$institutions = computed(fn () => Institution::query()->where('is_active', true)->orderBy('name')->get());

$resetForm = function (): void {
    $this->reset(
        'editingId',
        'name',
        'username',
        'email',
        'password',
        'role',
        'account_status',
        'institutionId'
    );

    $this->role = 'scholar';
    $this->account_status = 'active';
    $this->institutionId = null;

    $this->resetValidation();
};

$openCreate = function (): void {
    $this->resetForm();
    $this->formOpen = true;
};

$toggleInstitution = function (int $id): void {
    $this->institutionId = (int) $this->institutionId === $id
        ? null
        : $id;

    $this->resetValidation('institutionId');
};

$edit = function (int $id): void {
    $account = User::with('institutions')->findOrFail($id);

    abort_if(
        $account->id === auth()->id(),
        403,
        'Use Profile Settings to update your own account.'
    );

    $this->resetValidation();

    $this->editingId = $account->id;
    $this->name = $account->name;
    $this->username = $account->username ?? '';
    $this->email = $account->email;
    $this->password = '';
    $this->role = $account->role;
    $this->account_status = $account->account_status;
    $this->institutionId = $account->institutions
        ->firstWhere('pivot.is_primary', true)?->id
        ?? $account->institutions->first()?->id;

    $this->formOpen = true;
};

$save = function (): void {
    $isEditing = filled($this->editingId);

    $validated = $this->validate([
        'name' => ['required', 'string', 'max:255'],
        'username' => [
            'nullable',
            'alpha_dash',
            'max:50',
            Rule::unique('users', 'username')->ignore($this->editingId),
        ],
        'email' => [
            'required',
            'email',
            'max:255',
            Rule::unique('users', 'email')->ignore($this->editingId),
        ],
        'password' => $isEditing
            ? ['nullable', 'string', 'min:8', 'max:255']
            : ['required', 'string', 'min:8', 'max:255'],
        'role' => [
            'required',
            Rule::in(['administrator', 'registrar', 'focal_person', 'scholar']),
        ],
        'account_status' => [
            'required',
            Rule::in(['active', 'inactive']),
        ],
        'institutionId' => [
            'required',
            'integer',
            Rule::exists('institutions', 'id')->where('is_active', true),
        ],
    ]);

    $attributes = collect($validated)
        ->except(['password', 'institutionId'])
        ->all();

    if ($isEditing) {
        $account = User::findOrFail($this->editingId);

        abort_if(
            $account->id === auth()->id(),
            403,
            'Use Profile Settings to update your own account.'
        );

        if (filled($validated['password'])) {
            $attributes['password'] = Hash::make($validated['password']);
        }

        $account->update($attributes);
    } else {
        $account = User::create([
            ...$attributes,
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);
    }

    $account->institutions()->sync([
        (int) $validated['institutionId'] => ['is_primary' => true],
    ]);

    unset($this->accounts);

    $this->resetForm();
    $this->formOpen = false;

    Flux::toast(
        variant: 'success',
        text: $isEditing ? 'User account updated.' : 'User account created.'
    );
};

$delete = function (int $id): void {
    $account = User::findOrFail($id);

    abort_if(
        $account->id === auth()->id(),
        403,
        'You cannot delete your own account.'
    );

    $account->delete();

    unset($this->accounts);

    Flux::toast(
        variant: 'success',
        text: 'User account deleted.'
    );
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-cyan-600">Administration</p>
            <flux:heading size="xl" class="mt-1 text-3xl font-black">User Accounts</flux:heading>
            <flux:text class="mt-1">Manage access by role and institution scope.</flux:text>
        </div>

        <flux:button variant="primary" icon="user-plus" wire:click="openCreate">
            Add user
        </flux:button>
    </div>

    @if ($formOpen)
        <flux:card class="bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading size="lg">
                        {{ $editingId ? 'Edit user account' : 'Add user account' }}
                    </flux:heading>
                    <flux:text class="mt-1">
                        Fields marked required are needed before saving.
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
                    <flux:input wire:model="name" label="Full name" required />

                    <flux:input wire:model="username" label="Username" />

                    <flux:input
                        wire:model="email"
                        label="Email address"
                        type="email"
                        required
                    />

                    <flux:input
                        wire:model="password"
                        :label="$editingId ? 'New password (optional)' : 'Password'"
                        type="password"
                        :required="! $editingId"
                        viewable
                    />

                    <flux:select wire:model="role" label="Role" required>
                        <flux:select.option value="administrator">System Administrator</flux:select.option>
                        <flux:select.option value="registrar">Registrar</flux:select.option>
                        <flux:select.option value="focal_person">UTPRAS / Focal Person</flux:select.option>
                        <flux:select.option value="scholar">Scholar / Student</flux:select.option>
                    </flux:select>

                    <flux:select wire:model="account_status" label="Account status" required>
                        <flux:select.option value="active">Active</flux:select.option>
                        <flux:select.option value="inactive">Inactive</flux:select.option>
                    </flux:select>
                </div>

                <div>
                    <p class="mb-3 text-sm font-semibold text-slate-700">
                        Institution access <span class="text-rose-500">*</span>
                    </p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($this->institutions as $institution)
                            <label
                                wire:key="institution-{{ $institution->id }}"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border p-4 transition
                                    {{ (string) $institutionId === (string) $institution->id
                                        ? 'border-cyan-500 bg-cyan-50 ring-1 ring-cyan-500'
                                        : 'border-slate-200 bg-white hover:border-cyan-300 hover:bg-slate-50' }}"
                            >
                                <input
                                    type="checkbox"
                                    value="{{ $institution->id }}"
                                    @checked((string) $institutionId === (string) $institution->id)
                                    wire:click="toggleInstitution({{ $institution->id }})"
                                    class="size-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500"
                                >

                                <span class="text-sm font-medium text-slate-700">
                                    {{ $institution->name }}
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @error('institutionId')
                        <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
                    <flux:button variant="ghost" type="button" wire:click="resetForm">
                        Reset
                    </flux:button>

                    <flux:button variant="primary" type="submit">
                        {{ $editingId ? 'Save changes' : 'Create user' }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    <flux:card class="bg-white p-2 shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[840px] text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr>
                        <th class="px-4 py-3">Account</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Institution scope</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->accounts as $account)
                        <tr>
                            <td class="px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <flux:avatar
                                        :name="$account->name"
                                        :initials="$account->initials()"
                                    />

                                    <div>
                                        <p class="font-semibold text-slate-800">
                                            {{ $account->name }}
                                        </p>
                                        <p class="text-xs text-slate-400">
                                            {{ $account->username ?: $account->email }}
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-4 text-slate-600">
                                {{ $account->roleLabel() }}
                            </td>

                            <td class="px-4 py-4 text-slate-600">
                                {{ $account->institutions->pluck('code')->join(', ') ?: 'Not assigned' }}
                            </td>

                            <td class="px-4 py-4">
                                <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $account->account_status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ str($account->account_status)->headline() }}
                                </span>
                            </td>

                            <td class="px-4 py-4 text-right">
                                @if ($account->id === auth()->id())
                                    <span class="text-xs text-slate-400">Current account</span>
                                @else
                                    <div class="flex justify-end gap-2">
                                        <flux:button
                                            size="sm"
                                            variant="filled"
                                            wire:click="edit({{ $account->id }})"
                                        >
                                            Edit
                                        </flux:button>

                                        <flux:button
                                            size="sm"
                                            variant="danger"
                                            wire:click="delete({{ $account->id }})"
                                            wire:confirm="Delete {{ $account->name }}? This cannot be undone."
                                        >
                                            Delete
                                        </flux:button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-slate-400">
                                No user accounts found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
