<?php

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use function Livewire\Volt\{computed, layout, title};

layout('layouts::app');
title('Pending Account Requests');

$institutionIds = computed(fn () => InstitutionScope::idsFor(auth()->user()));

$requests = computed(fn () => User::query()
    ->with('institutions')
    ->where('account_status', 'pending')
    ->whereHas('institutions', fn ($query) => $query->whereIn('institutions.id', $this->institutionIds))
    ->orderBy('created_at')
    ->get());

$approve = function (int $id): void {
    $user = User::query()->with('institutions')->findOrFail($id);
    abort_unless($user->account_status === 'pending', 422, 'This account is no longer pending.');
    abort_unless($user->institutions->pluck('id')->intersect(InstitutionScope::idsFor(auth()->user()))->isNotEmpty(), 403);

    // Registrars can approve learner-facing accounts only, never grant staff privileges.
    abort_unless($user->hasRole('applicant', 'scholar'), 403, 'Only an Administrator may activate staff accounts.');

    $before = ActivityLogger::snapshot($user, ['account_status', 'email_verified_at']);
    $user->forceFill(['account_status' => 'active', 'email_verified_at' => now()])->save();
    ActivityLogger::record('account_request_approved', 'Approved account request for '.$user->name.'.', (int) $user->institutions->first()?->id, [
        'account_id' => $user->id,
        'changes' => ActivityLogger::changes($user, $before),
    ]);

    unset($this->requests);
    Flux::toast(variant: 'success', text: 'Account request approved. The user can now sign in.');
};

$reject = function (int $id): void {
    $user = User::query()->with('institutions')->findOrFail($id);
    abort_unless($user->account_status === 'pending', 422, 'This account is no longer pending.');
    abort_unless($user->institutions->pluck('id')->intersect(InstitutionScope::idsFor(auth()->user()))->isNotEmpty(), 403);
    abort_unless($user->hasRole('applicant', 'scholar'), 403, 'Only an Administrator may decide staff account requests.');

    $before = ActivityLogger::snapshot($user, ['account_status']);
    $user->forceFill(['account_status' => 'inactive'])->save();
    ActivityLogger::record('account_request_rejected', 'Rejected account request for '.$user->name.'.', (int) $user->institutions->first()?->id, [
        'account_id' => $user->id,
        'changes' => ActivityLogger::changes($user, $before),
    ]);

    unset($this->requests);
    Flux::toast(variant: 'success', text: 'Account request rejected and the account left inactive.');
};
?>

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <div>
        <p class="text-sm font-semibold text-blue-700">Dashboard / Account Management</p>
        <flux:heading size="xl" class="mt-1 text-3xl font-black text-slate-900">Pending Account Requests</flux:heading>
        <flux:text class="mt-1">Review pending user accounts assigned to your institution. Registrars can approve learner accounts; staff-role accounts remain Administrator-controlled.</flux:text>
    </div>

    <flux:card class="bg-white p-0 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
            <div><flux:heading size="lg">Requests awaiting review</flux:heading><flux:text class="mt-1">{{ $this->requests->count() }} pending account(s)</flux:text></div>
            <flux:button variant="ghost" icon="arrow-path" wire:click="$refresh">Refresh</flux:button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[850px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Applicant</th><th class="px-5 py-3">Role</th><th class="px-5 py-3">Institution(s)</th><th class="px-5 py-3">Requested</th><th class="px-5 py-3">Action</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->requests as $requestUser)
                        <tr wire:key="account-request-{{ $requestUser->id }}">
                            <td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ $requestUser->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $requestUser->email }}</p><p class="mt-1 text-xs text-slate-400">{{ $requestUser->username ?: 'No username' }}</p></td>
                            <td class="px-5 py-4">{{ $requestUser->roleLabel() }}</td>
                            <td class="px-5 py-4">{{ $requestUser->institutions->pluck('code')->join(', ') ?: 'Not assigned' }}</td>
                            <td class="px-5 py-4 text-slate-500">{{ $requestUser->created_at?->format('M j, Y g:i A') }}</td>
                            <td class="px-5 py-4"><div class="flex flex-wrap gap-2">
                                @if ($requestUser->hasRole('applicant', 'scholar'))
                                    <flux:button size="sm" variant="primary" wire:click="approve({{ $requestUser->id }})" wire:confirm="Approve this learner account?">Approve</flux:button>
                                @else
                                    <span class="self-center text-xs font-medium text-amber-700">Administrator approval required</span>
                                @endif
                                @if ($requestUser->hasRole('applicant', 'scholar'))
                                    <flux:button size="sm" variant="danger" wire:click="reject({{ $requestUser->id }})" wire:confirm="Reject this request and leave the account inactive?">Reject</flux:button>
                                @endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-12 text-center"><flux:heading size="md">No pending account requests</flux:heading><flux:text class="mt-2">Accounts marked Pending by an Administrator appear here when assigned to your institution.</flux:text></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
