<?php

use App\Models\EnrollmentApplication;
use App\Models\Institution;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Institution Records');
state(['editingId'=>null,'code'=>'','name'=>'','address'=>'','contact_email'=>'','contact_number'=>'','institutionFilter'=>request()->query('institution',''),'showSummary'=>(bool)request()->query('summary')]);
$institutions = computed(fn () => Institution::query()->whereIn('id', InstitutionScope::idsFor(auth()->user()))->when(filled($this->institutionFilter) && ! $this->showSummary, fn ($q) => $q->where('code', $this->institutionFilter))->withCount(['users','courses','batches'])->orderBy('code')->get());
$summary = computed(function () {
    return $this->institutions->mapWithKeys(function (Institution $institution) {
        $records = EnrollmentApplication::query()->where('institution_id',$institution->id);
        return [$institution->code => ['institution'=>$institution,'total'=>(clone $records)->count(),'submitted'=>(clone $records)->whereIn('application_status',['submitted','under_review','returned_for_revision'])->count(),'confirmed'=>(clone $records)->where('application_status','confirmed')->count(),'active_courses'=>$institution->courses()->where('is_active',true)->count(),'focal_persons'=>$institution->users()->where('role','focal_person')->where('account_status','active')->count()]];
    });
});
$edit = function (int $id): void {
    $institution = Institution::query()->whereIn('id', InstitutionScope::idsFor(auth()->user()))->findOrFail($id);
    $this->editingId=$institution->id; $this->code=$institution->code; $this->name=$institution->name; $this->address=$institution->address ?? ''; $this->contact_email=$institution->contact_email ?? ''; $this->contact_number=$institution->contact_number ?? '';
};
$save = function (): void {
    $institution = Institution::query()->whereIn('id', InstitutionScope::idsFor(auth()->user()))->findOrFail($this->editingId);
    $data=$this->validate(['name'=>['required','string','max:180'],'address'=>['nullable','string','max:1000'],'contact_email'=>['nullable','email','max:255'],'contact_number'=>['nullable','string','max:60']]);
    $before=ActivityLogger::snapshot($institution,['name','address','contact_email','contact_number']);
    $institution->update($data);
    ActivityLogger::record('institution_profile_updated','Updated institution profile details.',(int)$institution->id,['institution_id'=>$institution->id,'changes'=>ActivityLogger::changes($institution,$before)]);
    $this->reset('editingId','code','name','address','contact_email','contact_number'); unset($this->institutions,$this->summary); Flux::toast(variant:'success',text:'Institution profile saved.');
};
?>

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <div><p class="text-sm font-semibold text-blue-700">Institutions</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Institution Records</flux:heading><flux:text class="mt-1">Profiles and enrollment summaries for institutions assigned to your Registrar account. Institution codes and activation status are administrator-controlled.</flux:text></div>
    @if($editingId)<flux:card class="bg-white p-5 shadow-sm"><flux:heading size="lg">Update {{ $code }} profile</flux:heading><form wire:submit="save" class="mt-4 grid gap-4 md:grid-cols-2"><flux:input value="{{ $code }}" label="Institution code" disabled/><flux:input wire:model="name" label="Institution name" required/><div class="md:col-span-2"><flux:textarea wire:model="address" label="Address" rows="2"/></div><flux:input wire:model="contact_email" type="email" label="Contact email"/><flux:input wire:model="contact_number" label="Contact number"/><div class="flex justify-end gap-2 md:col-span-2"><flux:button type="button" variant="ghost" wire:click="$set('editingId',null)">Cancel</flux:button><flux:button type="submit" variant="primary">Save profile</flux:button></div></form></flux:card>@endif
    @if($this->institutions->count()>1)<div class="grid gap-4 lg:grid-cols-2">@foreach($this->institutions as $institution)@php($stats=$this->summary[$institution->code])<flux:card wire:key="institution-summary-{{ $institution->id }}" class="bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-blue-700">{{ $institution->code }}</p><flux:heading size="lg" class="mt-1">{{ $institution->name }}</flux:heading><flux:text class="mt-1">{{ $institution->address ?: 'No address on file' }}</flux:text></div><flux:button size="sm" variant="outline" wire:click="edit({{ $institution->id }})">Edit profile</flux:button></div><dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3"><div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">Enrollment records</dt><dd class="mt-1 text-2xl font-black">{{ $stats['total'] }}</dd></div><div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">Pending</dt><dd class="mt-1 text-2xl font-black">{{ $stats['submitted'] }}</dd></div><div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">Confirmed</dt><dd class="mt-1 text-2xl font-black">{{ $stats['confirmed'] }}</dd></div><div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">Active courses</dt><dd class="mt-1 text-2xl font-black">{{ $stats['active_courses'] }}</dd></div><div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">Active focal persons</dt><dd class="mt-1 text-2xl font-black">{{ $stats['focal_persons'] }}</dd></div><div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">Batches</dt><dd class="mt-1 text-2xl font-black">{{ $institution->batches_count }}</dd></div></dl></flux:card>@endforeach</div>@else
        @foreach($this->institutions as $institution)@php($stats=$this->summary[$institution->code])<flux:card class="bg-white p-5 shadow-sm"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-blue-700">{{ $institution->code }}</p><flux:heading size="lg" class="mt-1">{{ $institution->name }}</flux:heading><flux:text>{{ $institution->address ?: 'No address on file' }}</flux:text></div><flux:button variant="outline" wire:click="edit({{ $institution->id }})">Edit profile</flux:button></div><div class="mt-5 grid gap-3 sm:grid-cols-3"><div class="rounded-xl bg-slate-50 p-4">Enrollment records: <strong>{{ $stats['total'] }}</strong></div><div class="rounded-xl bg-slate-50 p-4">Active courses: <strong>{{ $stats['active_courses'] }}</strong></div><div class="rounded-xl bg-slate-50 p-4">Focal persons: <strong>{{ $stats['focal_persons'] }}</strong></div></div></flux:card>@endforeach
    @endif
    @if($this->institutions->isEmpty())<flux:card class="bg-white p-8 text-center text-slate-500">No institution is assigned to your account. Contact the Administrator.</flux:card>@endif
</div>
