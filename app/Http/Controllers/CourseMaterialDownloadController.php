<?php

namespace App\Http\Controllers;

use App\Models\CourseMaterial;
use App\Models\EnrollmentApplication;
use App\Support\InstitutionScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CourseMaterialDownloadController extends Controller
{
    public function __invoke(Request $request, CourseMaterial $material)
    {
        $user = $request->user();
        abort_unless($user && $user->account_status === 'active', 403);

        $canManage = $user->hasRole('administrator', 'focal_person')
            && InstitutionScope::idsFor($user)->contains((int) $material->course()->value('institution_id'));

        $canLearn = $user->hasRole('scholar', 'applicant')
            && $material->is_published
            && EnrollmentApplication::query()
                ->where('learner_user_id', $user->id)
                ->where('application_status', 'confirmed')
                ->where('course_id', $material->course_id)
                ->where('institution_id', $material->course()->value('institution_id'))
                ->exists();

        abort_unless($canManage || $canLearn, 403);
        abort_unless(Storage::disk('local')->exists($material->path), 404);

        return Storage::disk('local')->download($material->path, basename(str_replace('\\', '/', $material->original_name)), [
            'Content-Type' => $material->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
