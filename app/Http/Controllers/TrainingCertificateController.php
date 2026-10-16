<?php

namespace App\Http\Controllers;

use App\Models\TrainingCertificate;
use App\Support\InstitutionScope;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingCertificateController extends Controller
{
    public function print(Request $request, TrainingCertificate $certificate): View
    {
        abort_unless($certificate->status === 'completed' && $certificate->issued_at !== null, 404);

        $certificate->load([
            'enrollmentApplication.learner',
            'enrollmentApplication.course',
            'enrollmentApplication.institution',
            'enrollmentApplication.trainingBatch',
            'issuer',
        ]);

        $application = $certificate->enrollmentApplication;
        $user = $request->user();

        $isOwner = $user->hasRole('applicant', 'scholar')
            && (int) $application->learner_user_id === (int) $user->id;
        $isAdministrator = $user->hasRole('administrator');
        $isAssignedFocalPerson = $user->hasRole('focal_person')
            && InstitutionScope::idsFor($user)->contains((int) $application->institution_id);

        abort_unless($isOwner || $isAdministrator || $isAssignedFocalPerson, 403);

        return view('certificates.print', [
            'certificate' => $certificate,
            'application' => $application,
        ]);
    }
}
