<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\EnrollmentApplication;
use App\Support\InstitutionScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnnouncementAttachmentController extends Controller
{
    public function download(Request $request, Announcement $announcement)
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_unless($announcement->attachment_path, 404);

        $canManage = $user->hasRole('administrator')
            || ($user->hasRole('registrar')
                && $announcement->institution_id !== null
                && InstitutionScope::idsFor($user)->contains((int) $announcement->institution_id));

        $audienceAllowed = $user->hasRole('focal_person')
            ? in_array($announcement->audience, ['all', 'focal_person'], true)
            : ($user->hasRole('scholar', 'applicant') && in_array($announcement->audience, ['all', 'scholar'], true));

        $hasInstitutionAccess = $announcement->institution_id === null
            || ($user->hasRole('focal_person')
                && InstitutionScope::idsFor($user)->contains((int) $announcement->institution_id))
            || ($user->hasRole('applicant', 'scholar')
                && EnrollmentApplication::query()
                    ->where('learner_user_id', $user->id)
                    ->where('institution_id', (int) $announcement->institution_id)
                    ->exists());

        $canViewPublished = $user->hasRole('focal_person', 'scholar', 'applicant')
            && ! $announcement->archived_at
            && $announcement->published_at !== null
            && $announcement->published_at->lessThanOrEqualTo(now())
            && ($announcement->expires_at === null || $announcement->expires_at->greaterThan(now()))
            && $audienceAllowed
            && $hasInstitutionAccess;

        abort_unless($canManage || $canViewPublished, 403);
        abort_unless(Storage::disk('local')->exists($announcement->attachment_path), 404);

        return Storage::disk('local')->download(
            $announcement->attachment_path,
            $announcement->attachment_name ?: basename($announcement->attachment_path),
            ['X-Content-Type-Options' => 'nosniff']
        );
    }
}
