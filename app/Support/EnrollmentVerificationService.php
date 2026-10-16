<?php

namespace App\Support;

use App\Models\EnrollmentApplication;
use App\Models\LearnerDocument;
use App\Models\LearnerProfile;

class EnrollmentVerificationService
{
    /**
     * Evaluate the learner's registration against the required profile fields
     * and supporting-document approvals before an application can be verified.
     *
     * @return array{complete: bool, missing: list<string>, percentage: int}
     */
    public function assess(EnrollmentApplication $application): array
    {
        $learnerId = $application->learner_user_id;
        $profile = $learnerId
            ? LearnerProfile::query()->where('user_id', $learnerId)->first()
            : null;

        $requirements = [
            'A registered learner account must be linked' => $learnerId !== null,
            'The TESDA learner profile must be completed' => $profile !== null,
        ];

        $requiredDocumentTypes = ['birth_certificate'];

        if ($profile) {
            $requiredFields = [
                'first_name' => 'First name',
                'last_name' => 'Last name',
                'city_municipality' => 'City / municipality',
                'province' => 'Province',
                'region' => 'Region',
                'contact_number' => 'Contact number',
                'nationality' => 'Nationality',
                'sex' => 'Sex',
                'civil_status' => 'Civil status',
                'birthdate' => 'Birthdate',
                'educational_attainment' => 'Educational attainment',
            ];

            foreach ($requiredFields as $field => $label) {
                $requirements[$label.' must be provided'] = filled($profile->{$field});
            }

            $requirements['Privacy consent must be recorded'] = $profile->privacy_consent === true;
            $requirements['Selected institution must match the enrollment'] =
                (int) $profile->institution_id === (int) $application->institution_id;
            $requirements['Selected course must match the enrollment'] =
                $profile->course_id !== null
                && $application->course_id !== null
                && (int) $profile->course_id === (int) $application->course_id;

            if (in_array($profile->educational_attainment, ['High School Graduate', 'College Graduate'], true)) {
                $requiredDocumentTypes[] = 'tor';
            }

            if ($profile->civil_status === 'Married') {
                $requiredDocumentTypes[] = 'marriage_contract';
            }
        }

        $documentLabels = [
            'birth_certificate' => 'Birth certificate',
            'tor' => 'Transcript of Records (TOR)',
            'marriage_contract' => 'Marriage contract',
        ];

        foreach ($requiredDocumentTypes as $documentType) {
            $verified = $learnerId !== null && $profile !== null && LearnerDocument::query()
                ->where('user_id', $learnerId)
                ->where('learner_profile_id', $profile->id)
                ->where('document_type', $documentType)
                ->where('status', 'verified')
                ->exists();

            $requirements[($documentLabels[$documentType] ?? str($documentType)->headline()).' must be verified'] = $verified;
        }

        $missing = [];
        $passed = 0;

        foreach ($requirements as $label => $satisfied) {
            if ($satisfied) {
                $passed++;
            } else {
                $missing[] = $label;
            }
        }

        $total = count($requirements);

        return [
            'complete' => $missing === [],
            'missing' => $missing,
            'percentage' => $total > 0 ? (int) round(($passed / $total) * 100) : 0,
        ];
    }
}
