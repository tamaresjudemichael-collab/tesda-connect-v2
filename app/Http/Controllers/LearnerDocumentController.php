<?php

namespace App\Http\Controllers;

use App\Models\LearnerDocument;
use Illuminate\Support\Facades\Storage;

class LearnerDocumentController extends Controller
{
    public function download(LearnerDocument $document)
    {
        $user = auth()->user();

        abort_unless($user && $user->account_status === 'active', 403);

        $allowed = false;

        if ($user->hasRole('administrator')) {
            $allowed = true;
        } elseif (
            $user->hasRole('applicant', 'scholar')
            && (int) $document->user_id === (int) $user->id
        ) {
            $allowed = true;
        } elseif ($user->hasRole('registrar', 'focal_person')) {
            $institutionId = $document->profile?->institution_id;

            if ($institutionId) {
                $allowed = $user->institutions()
                    ->where('institutions.id', $institutionId)
                    ->exists();
            }
        }

        abort_unless($allowed, 403);

        $expectedPrefix = 'learner-documents/'.$document->user_id.'/';

        abort_unless(
            str_starts_with($document->path, $expectedPrefix),
            403
        );

        $disk = Storage::disk('local');

        abort_unless($disk->exists($document->path), 404);

        $downloadName = basename(
            str_replace('\\', '/', $document->original_name)
        );

        return $disk->download(
            $document->path,
            $downloadName ?: 'learner-document-'.$document->id,
            [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
