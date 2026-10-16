<?php

namespace App\Support;

use App\Models\EnrollmentApplication;
use App\Models\EnrollmentStatusHistory;

class EnrollmentStatusRecorder
{
    public static function record(
        EnrollmentApplication $application,
        ?string $fromStatus,
        string $toStatus,
        ?string $reason = null,
    ): void {
        EnrollmentStatusHistory::query()->create([
            'enrollment_application_id' => $application->exists ? $application->id : null,
            'reference_no' => $application->reference_no,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => auth()->id(),
            'reason' => $reason,
        ]);
    }
}
