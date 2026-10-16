<?php

use App\Models\Course;
use App\Models\EnrollmentApplication;
use App\Models\EnrollmentBatchForwarding;
use App\Models\TrainingBatch;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts::app');
title('Send Confirmed Batch to Administrator');

state([
    'institution_id' => '',
    'course_id' => '',
    'training_batch_id' => '',
]);

$institutionIds = computed(fn () => InstitutionScope::idsFor(auth()->user()));
$institutions = computed(fn () => \App\Models\Institution::query()->whereIn('id', $this->institutionIds)->where('is_active', true)->orderBy('name')->get());
$courses = computed(fn () => Course::query()->with('institution')->whereIn('institution_id', $this->institutionIds)->where('is_active', true)->when($this->institution_id, fn ($query) => $query->where('institution_id', $this->institution_id))->orderBy('name')->get());
$batches = computed(fn () => TrainingBatch::query()->with(['institution', 'course'])->whereIn('institution_id', $this->institutionIds)->when($this->institution_id, fn ($query) => $query->where('institution_id', $this->institution_id))->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))->whereNotIn('status', ['completed', 'cancelled'])->orderBy('starts_at')->get());

$eligibleApplications = computed(function () {
    if (! filled($this->training_batch_id)) return collect();
    return EnrollmentApplication::query()->with(['institution', 'course', 'trainingBatch'])
        ->whereIn('institution_id', $this->institutionIds)
        ->where('training_batch_id', (int) $this->training_batch_id)
        ->where('course_id', (int) $this->course_id)
        ->where('application_status', 'confirmed')
        ->whereNotNull('confirmed_at')
        ->whereNull('archived_at')
        ->whereNull('forwarding_id')
        ->orderBy('learner_name')->get();
});

$recentForwardings = computed(fn () => EnrollmentBatchForwarding::query()->with(['institution', 'course', 'trainingBatch', 'forwardedBy'])
    ->whereIn('institution_id', $this->institutionIds)->latest('forwarded_at')->take(30)->get());

$updatedInstitutionId = function (): void {
    $this->course_id = '';
    $this->training_batch_id = '';
    unset($this->courses, $this->batches, $this->eligibleApplications);
};
$updatedCourseId = function (): void {
    $this->training_batch_id = '';
    unset($this->batches, $this->eligibleApplications);
};
$updatedTrainingBatchId = function (): void { unset($this->eligibleApplications); };

$forwardBatch = function (): void {
    $validated = $this->validate([
        'institution_id' => ['required', Rule::in($this->institutionIds->map(fn ($id) => (string) $id)->all())],
        'course_id' => ['required', Rule::exists('courses', 'id')->where(fn ($query) => $query->where('institution_id', (int) $this->institution_id)), 'integer'],
        'training_batch_id' => ['required', Rule::exists('training_batches', 'id')->where(fn ($query) => $query->where('institution_id', (int) $this->institution_id)->where('course_id', (int) $this->course_id)), 'integer'],
    ]);

    $batch = TrainingBatch::query()->with(['institution', 'course'])
        ->whereIn('institution_id', $this->institutionIds)
        ->where('institution_id', (int) $validated['institution_id'])
        ->where('course_id', (int) $validated['course_id'])
        ->findOrFail((int) $validated['training_batch_id']);

    $applications = EnrollmentApplication::query()->with(['institution', 'course', 'trainingBatch'])
        ->where('institution_id', $batch->institution_id)
        ->where('course_id', $batch->course_id)
        ->where('training_batch_id', $batch->id)
        ->where('application_status', 'confirmed')->whereNotNull('confirmed_at')
        ->whereNull('archived_at')->whereNull('forwarding_id')->orderBy('learner_name')->get();

    if ($applications->isEmpty()) {
        Flux::toast(variant: 'warning', text: 'There are no confirmed, unforwarded scholar records in this batch.');
        return;
    }

    $reference = 'FWD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
    $filename = $reference.'-summary.csv';
    $path = 'enrollment-forwardings/'.$filename;
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, ['Scholar ID', 'Reference Number', 'Learner Name', 'Email', 'Contact Number', 'Institution Code', 'Institution Name', 'Course Code', 'Course Name', 'Training Batch', 'Confirmed At']);
    foreach ($applications as $application) {
        $values = [
            $application->scholar_id, $application->reference_no, $application->learner_name,
            $application->email, $application->contact_number, $application->institution?->code,
            $application->institution?->name, $application->course?->code, $application->course?->name,
            $application->trainingBatch?->name, $application->confirmed_at?->toDateTimeString(),
        ];
        fputcsv($stream, array_map(function ($value): string {
            $text = $value === null ? '' : (string) $value;
            return preg_match('/^[=+\-@\t\r]/u', $text) ? "'".$text : $text;
        }, $values));
    }
    rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
    abort_unless(is_string($csv), 500, 'Could not create the summary sheet.');
    Storage::disk('local')->put($path, $csv);

    $forwarding = EnrollmentBatchForwarding::create([
        'reference_code' => $reference,
        'institution_id' => $batch->institution_id,
        'course_id' => $batch->course_id,
        'training_batch_id' => $batch->id,
        'forwarded_by_user_id' => auth()->id(),
        'forwarded_at' => now(),
        'status' => 'ready_for_scheduling',
        'application_count' => $applications->count(),
        'summary_path' => $path,
        'summary_filename' => $filename,
    ]);

    EnrollmentApplication::query()->whereIn('id', $applications->pluck('id'))->update(['forwarding_id' => $forwarding->id]);
    ActivityLogger::record('enrollment_batch_forwarded', 'Forwarded confirmed batch '.$batch->name.' to Administrator for scheduling.', (int) $batch->institution_id, [
        'forwarding_id' => $forwarding->id, 'reference_code' => $reference, 'course_id' => $batch->course_id,
        'training_batch_id' => $batch->id, 'application_count' => $applications->count(), 'status' => 'ready_for_scheduling',
    ]);

    $this->reset('training_batch_id');
    unset($this->eligibleApplications, $this->recentForwardings, $this->batches);
    Flux::toast(variant: 'success', text: 'Confirmed batch forwarded with a downloadable summary sheet.');
};
?>

<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <div><p class="text-sm font-semibold text-blue-700">Enrollment Management</p><flux:heading size="xl" class="mt-1 text-3xl font-black">Send Confirmed Batch to Administrator</flux:heading><flux:text class="mt-1">Forward confirmed scholars to the Administrator's scheduling queue. Each forward creates an audit record and a private CSV summary sheet.</flux:text></div>

    <flux:card class="bg-white p-5 shadow-sm"><flux:heading size="lg">Choose a batch</flux:heading>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <flux:select wire:model.live="institution_id" label="Institution" required><flux:select.option value="">Select institution</flux:select.option>@foreach ($this->institutions as $institution)<flux:select.option value="{{ $institution->id }}">{{ $institution->code }} — {{ $institution->name }}</flux:select.option>@endforeach</flux:select>
            <flux:select wire:model.live="course_id" label="Course" required><flux:select.option value="">Select course</flux:select.option>@foreach ($this->courses as $course)<flux:select.option value="{{ $course->id }}">{{ $course->name }}</flux:select.option>@endforeach</flux:select>
            <flux:select wire:model.live="training_batch_id" label="Training batch" required><flux:select.option value="">Select batch</flux:select.option>@foreach ($this->batches as $batch)<flux:select.option value="{{ $batch->id }}">{{ $batch->code }} — {{ $batch->name }}</flux:select.option>@endforeach</flux:select>
        </div>
        @error('training_batch_id')<p class="mt-2 text-sm text-rose-700">{{ $message }}</p>@enderror
        <div class="mt-5 rounded-xl border border-slate-200">
            <div class="flex items-center justify-between border-b border-slate-100 p-4"><div><flux:heading size="md">Eligible confirmed scholars</flux:heading><flux:text class="mt-1">Only confirmed, unarchived records not already forwarded are included.</flux:text></div><span class="rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-700">{{ $this->eligibleApplications->count() }} record(s)</span></div>
            <div class="overflow-x-auto"><table class="w-full min-w-[700px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Scholar ID</th><th class="px-4 py-3">Learner</th><th class="px-4 py-3">Course / batch</th><th class="px-4 py-3">Confirmed</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse ($this->eligibleApplications as $application)<tr><td class="px-4 py-3 font-semibold">{{ $application->scholar_id }}</td><td class="px-4 py-3">{{ $application->learner_name }}<p class="mt-1 text-xs text-slate-500">{{ $application->reference_no }}</p></td><td class="px-4 py-3">{{ $application->course?->name }}<p class="mt-1 text-xs text-slate-500">{{ $application->trainingBatch?->name }}</p></td><td class="px-4 py-3">{{ $application->confirmed_at?->format('M j, Y') }}</td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Select a batch to view eligible records.</td></tr>@endforelse</tbody></table></div>
            <div class="flex justify-end border-t border-slate-100 p-4"><flux:button variant="primary" icon="paper-airplane" wire:click="forwardBatch" wire:confirm="Forward these confirmed records to Administrator and mark them Ready for Scheduling?" :disabled="$this->eligibleApplications->isEmpty()">Forward batch to Admin</flux:button></div>
        </div>
    </flux:card>

    <flux:card class="bg-white p-0 shadow-sm"><div class="border-b border-slate-100 p-5"><flux:heading size="lg">Forwarding history</flux:heading><flux:text class="mt-1">Recent forwards for your assigned institution(s).</flux:text></div><div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Reference</th><th class="px-5 py-3">Batch</th><th class="px-5 py-3">Scholars</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Forwarded</th><th class="px-5 py-3">Summary</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse ($this->recentForwardings as $forwarding)<tr wire:key="forwarding-{{ $forwarding->id }}"><td class="px-5 py-4 font-semibold">{{ $forwarding->reference_code }}<p class="mt-1 text-xs font-normal text-slate-500">{{ $forwarding->institution?->code }}</p></td><td class="px-5 py-4">{{ $forwarding->trainingBatch?->name ?? 'Batch removed' }}<p class="mt-1 text-xs text-slate-500">{{ $forwarding->course?->name }}</p></td><td class="px-5 py-4">{{ $forwarding->application_count }}</td><td class="px-5 py-4"><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">Ready for Scheduling</span></td><td class="px-5 py-4">{{ $forwarding->forwarded_at?->format('M j, Y g:i A') }}</td><td class="px-5 py-4"><a class="font-semibold text-blue-700 hover:underline" href="{{ route('enrollment-forwardings.summary', $forwarding) }}">Download CSV</a></td></tr>@empty<tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No batch has been forwarded yet.</td></tr>@endforelse</tbody></table></div></flux:card>
</div>
