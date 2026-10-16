<?php

use App\Models\Institution;
use App\Models\OutgoingDocumentLog;
use App\Models\TrainingBatch;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, mount, state, title};

layout('layouts::app');
title('Forms, Templates & Secretarial Work');

state([
    'institutionId' => '', 'batchId' => '', 'attendanceDate' => now()->toDateString(),
    'documentType' => 'outgoing_correspondence', 'referenceNumber' => '', 'recipient' => '',
    'subject' => '', 'sentAt' => now()->toDateString(), 'method' => 'physical', 'notes' => '',
    'search' => '',
]);

mount(function (): void {
    abort_unless(auth()->user()?->account_status === 'active' && auth()->user()->hasRole('focal_person'), 403);
    $this->institutionId = (string) (InstitutionScope::idsFor(auth()->user())->first() ?? '');
});

$institutions = computed(fn () => Institution::query()->whereIn('id', InstitutionScope::idsFor(auth()->user()))->where('is_active', true)->orderBy('name')->get());
$batches = computed(fn () => TrainingBatch::query()->with('course')->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()))->orderByDesc('starts_at')->orderBy('name')->get());
$logs = computed(function () {
    $query = OutgoingDocumentLog::query()->with(['institution','logger'])->whereIn('institution_id', InstitutionScope::idsFor(auth()->user()));
    if ($this->institutionId !== '') $query->where('institution_id', (int) $this->institutionId);
    $search = trim((string) $this->search);
    if ($search !== '') $query->where(fn ($q) => $q->where('subject','like','%'.$search.'%')->orWhere('recipient','like','%'.$search.'%')->orWhere('reference_number','like','%'.$search.'%'));
    return $query->latest('sent_at')->latest()->limit(100)->get();
});

$saveLog = function (): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('focal_person'), 403);
    abort_unless(InstitutionScope::idsFor($user)->contains((int) $this->institutionId), 403);
    $data = $this->validate([
        'institutionId' => ['required','integer',Rule::exists('institutions','id')->where('is_active',true)],
        'documentType' => ['required',Rule::in(['outgoing_correspondence','meeting_minutes','trainee_roster','daily_time_record','tesda_report','other'])],
        'referenceNumber' => ['nullable','string','max:100'], 'recipient' => ['nullable','string','max:180'],
        'subject' => ['required','string','max:255'], 'sentAt' => ['required','date','before_or_equal:today'],
        'method' => ['required',Rule::in(['physical','email','courier','portal','other'])], 'notes' => ['nullable','string','max:3000'],
    ]);
    $log = OutgoingDocumentLog::create([
        'institution_id' => (int) $data['institutionId'], 'logged_by_user_id' => $user->id,
        'document_type' => $data['documentType'], 'reference_number' => $data['referenceNumber'] ?: null,
        'recipient' => $data['recipient'] ?: null, 'subject' => trim($data['subject']),
        'sent_at' => $data['sentAt'], 'method' => $data['method'], 'notes' => $data['notes'] ?: null,
    ]);
    ActivityLogger::record('outgoing_document_logged', 'Logged outgoing document: '.$log->subject, (int) $log->institution_id, ['outgoing_document_log_id'=>$log->id, 'reference_number'=>$log->reference_number, 'recipient'=>$log->recipient]);
    $this->reset('referenceNumber','recipient','subject','notes');
    unset($this->logs);
    Flux::toast(variant: 'success', text: 'Outgoing document logged.');
};

$deleteLog = function (int $id): void {
    $user = auth()->user();
    abort_unless($user && $user->account_status === 'active' && $user->hasRole('focal_person'), 403);
    $log = OutgoingDocumentLog::query()->whereIn('institution_id', InstitutionScope::idsFor($user))->findOrFail($id);
    abort_unless((int) $log->logged_by_user_id === (int) $user->id, 403, 'Only the staff member who created this log may remove it.');
    $log->delete();
    ActivityLogger::record('outgoing_document_log_deleted', 'Removed outgoing document log: '.$log->subject, (int) $log->institution_id, ['outgoing_document_log_id'=>$id]);
    unset($this->logs);
    Flux::toast(variant: 'success', text: 'Outgoing log entry removed.');
};
?>

<div class="mx-auto max-w-7xl space-y-6">
    <header><p class="text-sm font-semibold text-cyan-700">Printable forms and office records</p><h1 class="mt-1 text-3xl font-black text-slate-900">Forms & Secretarial Work</h1><p class="mt-2 max-w-3xl text-sm text-slate-600">Download data as CSV for Excel, or open print-ready layouts and choose Print → Save as PDF in your browser. Downloaded files can be saved to your device for offline access.</p></header>

    <section class="space-y-4"><div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-end"><div class="flex-1"><label class="mb-1 block text-sm font-medium text-slate-700">Optional batch filter</label><select wire:model.live="batchId" class="w-full rounded-lg border-slate-300 text-sm"><option value="">All assigned batches</option>@foreach($this->batches as $batch)<option value="{{ $batch->id }}">{{ $batch->code }} — {{ $batch->name }} ({{ $batch->institution?->code }})</option>@endforeach</select></div><div><label class="mb-1 block text-sm font-medium text-slate-700">Attendance date (optional filter)</label><input type="date" wire:model="attendanceDate" class="rounded-lg border-slate-300 text-sm" /></div></div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach([
                ['progress-chart-template','Blank Progress Chart Template','Blank competency chart columns for offline preparation.'],
                ['tesda-form-template','TESDA Forms Checklist','Working checklist; verify the latest official form revision before submission.'],
                ['batch-summary','Batch Summary','Actual assigned batch capacity, confirmed trainees, and remaining slots.'],
                ['enrollment-summary','Enrollment Summary','Confirmed learner details with reference and Scholar ID.'],
                ['attendance-sheet','Attendance Sheet','Confirmed trainee roster and available attendance records.'],
                ['progress-report','Competency Progress Report','Competency task status and assessment timestamps.'],
                ['trainee-roster','Trainee Roster','Confirmed trainees in assigned institutions or selected batch.'],
                ['meeting-minutes-template','Meeting Minutes Template','Blank meeting, agenda, decision, owner, and due date columns.'],
                ['daily-time-record-template','Daily Time Record Template','Blank time in/out, total hours, signature, and remarks columns.'],
            ] as [$form,$label,$description])
                @php($suffix = ($batchId ? '?batch_id='.(int)$batchId : '').($form === 'attendance-sheet' ? (($batchId ? '&' : '?').'attendance_date='.urlencode($attendanceDate)) : ''))
                <flux:card class="flex flex-col bg-white p-5 shadow-sm"><flux:heading size="md">{{ $label }}</flux:heading><flux:text class="mt-2 flex-1">{{ $description }}</flux:text><div class="mt-4 flex flex-wrap gap-2"><flux:button size="sm" variant="primary" :href="route('focal.forms.download', ['form'=>$form]).$suffix" icon="arrow-down-tray">Download CSV</flux:button><flux:button size="sm" variant="outline" :href="route('focal.forms.print', ['form'=>$form]).$suffix" target="_blank" icon="printer">Print / Save PDF</flux:button></div></flux:card>
            @endforeach
        </div>
    </section>

    <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div><flux:heading size="lg">Secretarial Work — Outgoing Document Log</flux:heading><flux:text class="mt-1">Track meeting minutes, trainee rosters, daily time records, reports, and documents sent to TESDA Provincial or other recipients.</flux:text></div>
        <form wire:submit="saveLog" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Institution</label><select wire:model="institutionId" required class="w-full rounded-lg border-slate-300 text-sm"><option value="">Select</option>@foreach($this->institutions as $institution)<option value="{{ $institution->id }}">{{ $institution->code }} — {{ $institution->name }}</option>@endforeach</select>@error('institutionId')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Document type</label><select wire:model="documentType" class="w-full rounded-lg border-slate-300 text-sm"><option value="outgoing_correspondence">Outgoing correspondence</option><option value="meeting_minutes">Meeting minutes</option><option value="trainee_roster">Trainee roster</option><option value="daily_time_record">Daily time record</option><option value="tesda_report">TESDA report</option><option value="other">Other</option></select></div>
            <flux:input wire:model="referenceNumber" label="Reference number" placeholder="Optional" />
            <flux:input wire:model="subject" label="Subject / document title" required />
            <flux:input wire:model="recipient" label="Recipient / receiving office" />
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Date sent</label><input wire:model="sentAt" type="date" max="{{ now()->toDateString() }}" required class="w-full rounded-lg border-slate-300 text-sm" />@error('sentAt')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="mb-1 block text-sm font-medium text-slate-700">Delivery method</label><select wire:model="method" class="w-full rounded-lg border-slate-300 text-sm"><option value="physical">Physical / received copy</option><option value="email">Email</option><option value="courier">Courier</option><option value="portal">Online portal</option><option value="other">Other</option></select></div>
            <div class="md:col-span-2 xl:col-span-3"><flux:textarea wire:model="notes" label="Notes / acknowledgement details" rows="2" /></div>
            <div class="flex justify-end md:col-span-2 xl:col-span-3"><flux:button type="submit" variant="primary" icon="plus">Record outgoing document</flux:button></div>
        </form>
        <div class="flex flex-col gap-3 sm:flex-row"><div class="flex-1"><flux:input wire:model.live.debounce.300ms="search" placeholder="Search subject, recipient, or reference" icon="magnifying-glass" /></div><div class="flex items-center gap-2 text-sm text-slate-500">{{ $this->logs->count() }} recent entries</div></div>
        <div class="overflow-x-auto"><table class="w-full min-w-[800px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-3 py-3">Date / Reference</th><th class="px-3 py-3">Document</th><th class="px-3 py-3">Recipient</th><th class="px-3 py-3">Institution / Method</th><th class="px-3 py-3">Logged by</th><th class="px-3 py-3">Action</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse($this->logs as $log)<tr wire:key="outgoing-log-{{ $log->id }}"><td class="px-3 py-4">{{ $log->sent_at?->format('M j, Y') }}<p class="text-xs text-slate-500">{{ $log->reference_number ?: 'No reference' }}</p></td><td class="px-3 py-4"><p class="font-semibold">{{ $log->subject }}</p><p class="text-xs text-slate-500">{{ str($log->document_type)->replace('_',' ')->headline() }}</p></td><td class="px-3 py-4">{{ $log->recipient ?: '—' }}</td><td class="px-3 py-4">{{ $log->institution?->code }}<p class="text-xs text-slate-500">{{ str($log->method)->headline() }}</p></td><td class="px-3 py-4">{{ $log->logger?->name ?: 'Former user' }}</td><td class="px-3 py-4">@if((int)$log->logged_by_user_id === (int)auth()->id())<flux:button size="sm" variant="ghost" wire:click="deleteLog({{ $log->id }})" wire:confirm="Remove this outgoing log entry? The action will remain in the audit log.">Remove</flux:button>@else<span class="text-xs text-slate-400">View only</span>@endif</td></tr>@empty<tr><td colspan="6" class="px-3 py-10 text-center text-slate-500">No outgoing documents logged yet.</td></tr>@endforelse</tbody></table></div>
    </section>
</div>
