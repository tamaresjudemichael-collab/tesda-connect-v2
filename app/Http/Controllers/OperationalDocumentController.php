<?php

namespace App\Http\Controllers;

use App\Models\OperationalDocument;
use App\Support\ActivityLogger;
use App\Support\InstitutionScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationalDocumentController extends Controller
{

    public function storeFromOfflineQueue(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->account_status === 'active' && $user->hasRole('focal_person'), 403);
        $data = $request->validate([
            'institution_id' => ['required', 'integer', Rule::exists('institutions', 'id')->where('is_active', true)],
            'category' => ['required', Rule::in(['attendance_sheet', 'assessment_form', 'photo', 'other'])],
            'title' => ['required', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'course_id' => ['nullable', 'integer'],
            'training_batch_id' => ['nullable', 'integer'],
            'enrollment_application_id' => ['nullable', 'integer'],
            'upload' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        $institutionId = (int) $data['institution_id'];
        abort_unless(InstitutionScope::idsFor($user)->contains($institutionId), 403);
        $course = null;
        $batch = null;
        $application = null;
        if (filled($data['course_id'] ?? null)) $course = \App\Models\Course::query()->whereKey((int) $data['course_id'])->where('institution_id', $institutionId)->firstOrFail();
        if (filled($data['training_batch_id'] ?? null)) $batch = \App\Models\TrainingBatch::query()->whereKey((int) $data['training_batch_id'])->where('institution_id', $institutionId)->firstOrFail();
        if (filled($data['enrollment_application_id'] ?? null)) $application = \App\Models\EnrollmentApplication::query()->whereKey((int) $data['enrollment_application_id'])->where('institution_id', $institutionId)->firstOrFail();
        if ($batch && $course && (int) $batch->course_id !== (int) $course->id) throw ValidationException::withMessages(['training_batch_id' => 'The selected batch does not belong to the selected course.']);
        if ($application && $batch && (int) $application->training_batch_id !== (int) $batch->id) throw ValidationException::withMessages(['enrollment_application_id' => 'The selected learner is not assigned to the selected batch.']);
        if ($application && $course && (int) $application->course_id !== (int) $course->id) throw ValidationException::withMessages(['enrollment_application_id' => 'The selected learner is not enrolled in the selected course.']);

        $file = $data['upload'];
        $document = OperationalDocument::create([
            'institution_id' => $institutionId,
            'uploaded_by_user_id' => $user->id,
            'course_id' => $course?->id,
            'training_batch_id' => $batch?->id,
            'enrollment_application_id' => $application?->id,
            'category' => $data['category'],
            'title' => trim($data['title']),
            'original_name' => $file->getClientOriginalName(),
            'path' => $file->store('operational-documents/'.$institutionId, 'local'),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'notes' => $data['notes'] ?: null,
        ]);
        ActivityLogger::record('operational_document_uploaded_offline_queue', 'Uploaded queued operational document '.$document->title, $institutionId, ['document_id' => $document->id, 'category' => $document->category, 'size' => $document->size]);

        return response()->json(['ok' => true, 'document_id' => $document->id, 'message' => 'Document synchronized.']);
    }

    public function download(Request $request, OperationalDocument $document)
    {
        $user = $request->user();
        abort_unless($user && $user->account_status === 'active' && $user->hasRole('focal_person'), 403);
        abort_unless(InstitutionScope::idsFor($user)->contains((int) $document->institution_id), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
