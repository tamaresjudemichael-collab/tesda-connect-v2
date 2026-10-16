<?php

namespace App\Support;

use App\Models\CompetencyProgress;
use App\Models\CompetencyReportSubmission;
use App\Models\CompetencyTask;
use App\Models\CompetencyUnit;
use App\Models\CompetencyUnitReview;
use App\Models\EnrollmentApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompetencyReportWorkflow
{
    public function review(User $user, int $enrollmentId): array
    {
        $application = $this->authorizedApplication($user, $enrollmentId);

        $readiness = $this->checkReadiness($application);

        if (! $readiness['ok']) {
            return $readiness;
        }

        return DB::transaction(function () use ($user, $application) {
            $submission = CompetencyReportSubmission::query()
                ->where('enrollment_application_id', $application->id)
                ->lockForUpdate()
                ->first();

            if ($submission && $submission->status !== 'draft') {
                if ($submission->status === 'reviewed') {
                    return [
                        'ok' => true,
                        'message' => 'This report has already been reviewed.',
                        'status' => 'reviewed',
                    ];
                }

                return [
                    'ok' => false,
                    'message' => 'Only a draft report can be reviewed.',
                ];
            }

            if (! $submission) {
                $submission = new CompetencyReportSubmission();
                $submission->enrollment_application_id = $application->id;
            }

            $submission->status = 'reviewed';
            $submission->reviewed_by = $user->id;
            $submission->reviewed_at = now();
            $submission->save();

            ActivityLogger::record(
                'competency_report_reviewed',
                "{$user->name} reviewed the competency report for {$application->learner_name}.",
                $application->institution_id,
                ['enrollment_application_id' => $application->id]
            );

            return [
                'ok' => true,
                'message' => 'Competency report reviewed successfully.',
                'status' => 'reviewed',
            ];
        });
    }

    public function finalize(User $user, int $enrollmentId): array
    {
        $application = $this->authorizedApplication($user, $enrollmentId);

        $readiness = $this->checkReadiness($application);

        if (! $readiness['ok']) {
            return $readiness;
        }

        return DB::transaction(function () use ($user, $application) {
            $submission = CompetencyReportSubmission::query()
                ->where('enrollment_application_id', $application->id)
                ->lockForUpdate()
                ->first();

            if (! $submission || $submission->status !== 'reviewed') {
                return [
                    'ok' => false,
                    'message' => 'Review the report before finalizing it.',
                ];
            }

            $submission->status = 'finalized';
            $submission->finalized_by = $user->id;
            $submission->finalized_at = now();
            $submission->save();

            ActivityLogger::record(
                'competency_report_finalized',
                "{$user->name} finalized the competency report for {$application->learner_name}.",
                $application->institution_id,
                ['enrollment_application_id' => $application->id]
            );

            return [
                'ok' => true,
                'message' => 'Competency report finalized successfully.',
                'status' => 'finalized',
            ];
        });
    }

    public function submit(
        User $user,
        int $enrollmentId,
        ?string $reference = null,
        ?string $remarks = null
    ): array {
        $application = $this->authorizedApplication($user, $enrollmentId);

        $reference = $reference !== null ? trim($reference) : null;
        $remarks = $remarks !== null ? trim($remarks) : null;

        $reference = $reference === '' ? null : $reference;
        $remarks = $remarks === '' ? null : $remarks;

        if ($reference !== null && mb_strlen($reference) > 100) {
            return [
                'ok' => false,
                'message' => 'Submission reference must not exceed 100 characters.',
            ];
        }

        if ($remarks !== null && mb_strlen($remarks) > 2000) {
            return [
                'ok' => false,
                'message' => 'Submission remarks must not exceed 2000 characters.',
            ];
        }

        $readiness = $this->checkReadiness($application);

        if (! $readiness['ok']) {
            return $readiness;
        }

        return DB::transaction(function () use (
            $user,
            $application,
            $reference,
            $remarks
        ) {
            $submission = CompetencyReportSubmission::query()
                ->where('enrollment_application_id', $application->id)
                ->lockForUpdate()
                ->first();

            if (! $submission || $submission->status !== 'finalized') {
                return [
                    'ok' => false,
                    'message' => 'Finalize the report before submitting it.',
                ];
            }

            $submission->status = 'submitted';
            $submission->submitted_by = $user->id;
            $submission->submitted_at = now();
            $submission->submission_reference = $reference;
            $submission->remarks = $remarks;
            $submission->save();

            ActivityLogger::record(
                'competency_report_submitted',
                "{$user->name} recorded submission of the competency report for {$application->learner_name}.",
                $application->institution_id,
                [
                    'enrollment_application_id' => $application->id,
                    'submission_reference' => $reference,
                ]
            );

            return [
                'ok' => true,
                'message' => 'Report submission recorded successfully.',
                'status' => 'submitted',
            ];
        });
    }

    private function authorizedApplication(
        User $user,
        int $enrollmentId
    ): EnrollmentApplication {
        abort_unless(
            $user->account_status === 'active'
                && $user->hasRole('administrator', 'focal_person'),
            403
        );

        $application = EnrollmentApplication::query()
            ->whereKey($enrollmentId)
            ->where('application_status', 'confirmed')
            ->firstOrFail();

        if (! $user->hasRole('administrator')) {
            abort_unless(
                $user->institutions()
                    ->where('institutions.id', $application->institution_id)
                    ->exists(),
                403
            );
        }

        return $application;
    }

    private function checkReadiness(EnrollmentApplication $application): array
    {
        $unitIds = CompetencyUnit::query()
            ->where('course_id', $application->course_id)
            ->pluck('id');

        if ($unitIds->isEmpty()) {
            return [
                'ok' => false,
                'message' => 'No competency units are configured for this course.',
            ];
        }

        $taskIds = CompetencyTask::query()
            ->whereIn('competency_unit_id', $unitIds)
            ->whereIn('chart_type', ['progress', 'achievement'])
            ->pluck('id');

        if ($taskIds->isEmpty()) {
            return [
                'ok' => false,
                'message' => 'No competency tasks are configured for this course.',
            ];
        }

        $assessedTaskIds = CompetencyProgress::query()
            ->where('enrollment_application_id', $application->id)
            ->whereIn('competency_task_id', $taskIds)
            ->where('status', '<>', 'not_assessed')
            ->pluck('competency_task_id');

        $pendingTasks = $taskIds->diff($assessedTaskIds)->count();

        $lockedUnitIds = CompetencyUnitReview::query()
            ->where('enrollment_application_id', $application->id)
            ->whereIn('competency_unit_id', $unitIds)
            ->where('is_locked', true)
            ->pluck('competency_unit_id')
            ->unique();

        $pendingUnits = $unitIds->diff($lockedUnitIds)->count();

        if ($pendingTasks > 0 || $pendingUnits > 0) {
            return [
                'ok' => false,
                'message' => "{$pendingTasks} task(s) need assessment and {$pendingUnits} unit(s) need verification before the report can proceed.",
            ];
        }

        return ['ok' => true];
    }
}