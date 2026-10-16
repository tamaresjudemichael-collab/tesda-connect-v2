<?php

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Staff Account Management');
state(['search' => '', 'tab' => request()->query('action', 'status'), 'temporaryPassword' => '', 'temporaryPasswordFor' => '']);
$institutionIds = computed(fn () => InstitutionScope::idsFor(auth()->user()));
$staffAccounts = computed(function () {
    return User::query()->with('institutions')
        ->where('id', '!=', auth()->id())
        ->whereIn('role', ['registrar', 'focal_person'])
        ->whereIn('account_status', ['active', 'inactive'])
        ->whereHas('institutions', fn ($q) => $q->whereIn('institutions.id', $this->institutionIds))
        ->whereDoesntHave('institutions', fn ($q) => $q->whereNotIn('institutions.id', $this->institutionIds))
        ->when(filled(trim($this->search)), fn ($q) => $q->where(fn ($sub) => $sub->where('name','like','%'.trim($this->search).'%')->orWhere('email','like','%'.trim($this->search).'%')->orWhere('username','like','%'.trim($this->search).'%')))
        ->orderBy('role')->orderBy('name')->get();
});
$findManaged = function (int $id): User {
    $account = User::query()->with('institutions')->findOrFail($id);
    abort_if($account->id === auth()->id() || $account->hasRole('administrator'), 403);
    abort_unless($account->hasRole('registrar', 'focal_person'), 403, 'Registrar account controls are limited to staff accounts.');
    abort_if($account->account_status === 'pending', 403, 'Pending staff accounts must be approved by an Administrator.');
    abort_unless($account->institutions->pluck('id')->isNotEmpty() && $account->institutions->pluck('id')->diff($this->institutionIds)->isEmpty(), 403, 'All institutions assigned to this staff account must be within your scope.');
    return $account;
};
$toggleStatus = function (int $id) use ($findManaged): void {
    $account = $findManaged($id);
    $next = $account->account_status === 'active' ? 'inactive' : 'active';
    $before = ActivityLogger::snapshot($account, ['account_status']);
    $account->forceFill(['account_status' => $next])->save();
    ActivityLogger::record('staff_account_status_changed', 'Changed staff account status for '.$account->name.'.', (int)$account->institutions->first()?->id, ['account_id'=>$account->id,'changes'=>ActivityLogger::changes($account,$before)]);
    unset($this->staffAccounts); Flux::toast(variant:'success',text:'Staff account is now '.$next.'.');
};
$verifyAccount = function (int $id) use ($findManaged): void {
    $account = $findManaged($id);
    $before = ActivityLogger::snapshot($account, ['email_verified_at']);
    $account->forceFill(['email_verified_at'=>now()])->save();
    ActivityLogger::record('staff_account_verified','Verified staff account '.$account->name.'.',(int)$account->institutions->first()?->id,['account_id'=>$account->id,'changes'=>ActivityLogger::changes($account,$before)]);
    unset($this->staffAccounts); Flux::toast(variant:'success',text:'Staff account marked as verified.');
};
$toggleExpiryExempt = function (int $id) use ($findManaged): void {
    $account = $findManaged($id);
    $next = ! (bool)$account->password_expiry_exempt;
    $before = ActivityLogger::snapshot($account, ['password_expiry_exempt','password_expires_at']);
    $account->forceFill(['password_expiry_exempt'=>$next,'password_expires_at'=>$next ? null : ($account->password_expires_at ?? now()->addDays(90))])->save();
    ActivityLogger::record('staff_password_expiry_exemption_changed','Changed password expiry exemption for '.$account->name.'.',(int)$account->institutions->first()?->id,['account_id'=>$account->id,'changes'=>ActivityLogger::changes($account,$before)]);
    unset($this->staffAccounts); Flux::toast(variant:'success',text:$next ? 'Password expiry exemption enabled.' : 'Password expiry re-enabled.');
};
$lockInactive = function (): void {
    $cutoff = now()->subDays(90);
    $accounts = User::query()->with('institutions')
        ->where('id', '!=', auth()->id())
        ->whereIn('role', ['registrar', 'focal_person'])
        ->where('account_status', 'active')
        ->whereHas('institutions', fn ($q) => $q->whereIn('institutions.id', $this->institutionIds))
        ->whereDoesntHave('institutions', fn ($q) => $q->whereNotIn('institutions.id', $this->institutionIds))
        ->where(fn ($q) => $q->where('last_login_at', '<=', $cutoff)
            ->orWhere(fn ($never) => $never->whereNull('last_login_at')->where('created_at', '<=', $cutoff)))
        ->get();

    foreach ($accounts as $account) {
        $account->forceFill(['account_status' => 'inactive'])->save();
        ActivityLogger::record('staff_account_auto_locked', 'Deactivated staff account after 90 days without a recorded login.', (int)$account->institutions->first()?->id, ['account_id' => $account->id, 'inactivity_days' => 90, 'last_login_at' => $account->last_login_at?->toIso8601String()]);
    }

    unset($this->staffAccounts);
    Flux::toast(variant: $accounts->isEmpty() ? 'warning' : 'success', text: $accounts->count().' staff account(s) locked for 90+ days of inactivity.');
};

$resetPassword = function (int $id) use ($findManaged): void {
    $account = $findManaged($id);
    $temporary = Str::password(14);
    $account->forceFill(['password'=>Hash::make($temporary),'force_password_change'=>true,'password_expires_at'=>null])->save();
    ActivityLogger::record('staff_password_reset','Generated a temporary password for '.$account->name.'.',(int)$account->institutions->first()?->id,['account_id'=>$account->id,'force_password_change'=>true]);
    $this->temporaryPassword = $temporary; $this->temporaryPasswordFor = $account->name;
    Flux::toast(variant:'success',text:'Temporary password generated. Copy it now and deliver it securely.');
};
?>

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <div><p class="text-sm font-semibold text-blue-700">Account Management</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Staff Accounts</flux:heading><flux:text class="mt-1">Manage assigned Registrar and Focal Person accounts. Administrator accounts and your own account are excluded from these controls.</flux:text></div>
    @if($temporaryPassword)<flux:card class="border-amber-200 bg-amber-50 p-5"><div class="flex flex-wrap items-start justify-between gap-3"><div><flux:heading size="lg">Temporary password generated</flux:heading><flux:text class="mt-1">For {{ $temporaryPasswordFor }}. Copy it now; it will not be shown again after this page state resets. The user must change it after signing in.</flux:text><p class="mt-3 break-all rounded-lg border border-amber-200 bg-white p-3 font-mono text-lg font-bold">{{ $temporaryPassword }}</p></div></div></flux:card>@endif
    <div class="flex flex-wrap gap-2"><flux:button variant="{{ $tab === 'status' ? 'primary' : 'outline' }}" wire:click="$set('tab','status')">Activate / Deactivate</flux:button><flux:button variant="{{ $tab === 'password' ? 'primary' : 'outline' }}" wire:click="$set('tab','password')">Password Reset</flux:button><flux:button variant="{{ $tab === 'verification' ? 'primary' : 'outline' }}" wire:click="$set('tab','verification')">Verify / Expiry</flux:button></div>
    <flux:card class="bg-white p-5 shadow-sm"><div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div class="flex-1"><flux:input wire:model.live.debounce.300ms="search" label="Search staff" placeholder="Name, username, or email…"/></div>@if($tab === 'status')<flux:button variant="danger" icon="users" wire:click="lockInactive" wire:confirm="Deactivate assigned Registrar/Focal Person accounts with no recorded login in the last 90 days? This will not affect your own account or Administrator accounts.">Lock inactive staff (90 days)</flux:button>@endif</div><div class="mt-4 space-y-3">
        @forelse($this->staffAccounts as $account)<article wire:key="managed-staff-{{ $account->id }}" class="rounded-xl border border-slate-200 p-4"><div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center"><div><div class="flex flex-wrap items-center gap-2"><p class="font-bold text-slate-900">{{ $account->name }}</p><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold">{{ $account->roleLabel() }}</span><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $account->account_status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ str($account->account_status)->headline() }}</span></div><p class="mt-1 text-sm text-slate-500">{{ $account->username }} · {{ $account->email }}</p><p class="mt-1 text-xs text-slate-400">Institution(s): {{ $account->institutions->pluck('code')->join(', ') }} · {{ $account->email_verified_at ? 'Verified' : 'Not verified' }} · {{ $account->password_expiry_exempt ? 'No password expiry' : 'Expiry: '.($account->password_expires_at?->format('M j, Y') ?? 'Not scheduled') }}</p></div><div class="flex flex-wrap gap-2">
            @if($tab === 'status')<flux:button size="sm" variant="{{ $account->account_status === 'active' ? 'danger' : 'primary' }}" wire:click="toggleStatus({{ $account->id }})" wire:confirm="{{ $account->account_status === 'active' ? 'Deactivate' : 'Activate' }} this staff account?">{{ $account->account_status === 'active' ? 'Deactivate' : 'Activate' }}</flux:button>
            @elseif($tab === 'password')<flux:button size="sm" variant="primary" wire:click="resetPassword({{ $account->id }})" wire:confirm="Generate a new temporary password? The current password will stop working.">Generate temporary password</flux:button>
            @else @if(!$account->email_verified_at)<flux:button size="sm" variant="primary" wire:click="verifyAccount({{ $account->id }})">Mark verified</flux:button>@endif<flux:button size="sm" variant="outline" wire:click="toggleExpiryExempt({{ $account->id }})">{{ $account->password_expiry_exempt ? 'Re-enable expiry' : 'No password expiry' }}</flux:button>@endif
        </div></div></article>@empty<div class="rounded-xl border border-dashed p-8 text-center text-slate-500">No assigned Registrar or Focal Person accounts match your search.</div>@endforelse
    </div></flux:card>
</div>
