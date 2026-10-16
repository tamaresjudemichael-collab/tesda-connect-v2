<?php

namespace App\Support;

use App\Models\CompetencyUnit;
use App\Models\EnrollmentApplication;

class TrainingCompletionService
{
    /**
     * Determine completion from persisted competency and unit-finalization data.
     * No grades or numerical assessment scores are used.
     *
     * @return array{eligible: bool, pending_units: list<string>, message: string}
     */
    public function assess(EnrollmentApplication $application): array
    {
        if ($application->application_status !== 'confirmed') {
            return $this->ineligible(['Enrollment is not confirmed.']);
        }

        if (! $application->course_id) {
            return $this->ineligible(['No course is assigned.']);
        }

        $batch = $application->trainingBatch;
        if (! $batch || ! $batch->starts_at || ! $batch->ends_at) {
            return $this->ineligible(['A training batch with start and end dates must be assigned.']);
        }

        $units = CompetencyUnit::query()
            ->where('course_id', $application->course_id)
            ->whereHas('tasks', fn ($query) => $query->whereIn('chart_type', ['progress', 'achievement']))
            ->with([
                'tasks' => fn ($query) => $query
                    ->whereIn('chart_type', ['progress', 'achievement'])
                    ->with(['progressRecords' => fn ($progress) => $progress
                        ->where('enrollment_application_id', $application->id)]),
                'reviews' => fn ($query) => $query
                    ->where('enrollment_application_id', $application->id),
            ])
            ->orderBy('sort_order')
            ->get();

        if ($units->isEmpty() || $units->flatMap(fn ($unit) => $unit->tasks)->isEmpty()) {
            return $this->ineligible(['Competency tasks have not been configured for this course.']);
        }

        $pending = [];

        foreach ($units as $unit) {
            $taskIncomplete = $unit->tasks->contains(function ($task): bool {
                $status = $task->progressRecords->first()?->status;

                return ! in_array($status, ['competent', 'submitted_output_correct'], true);
            });

            $review = $unit->reviews->first();
            $unitNotFinalized = ! $review
                || $review->status !== 'verified'
                || ! $review->is_locked;

            if ($taskIncomplete || $unitNotFinalized) {
                $pending[] = 'Unit '.$unit->unit_number.' — '.$unit->name;
            }
        }

        $pending = array_values(array_unique($pending));

        if ($pending !== []) {
            return $this->ineligible($pending);
        }

        return [
            'eligible' => true,
            'pending_units' => [],
            'message' => 'All configured competency tasks are competent and all units are verified and locked.',
        ];
    }

    /** @param list<string> $pending */
    private function ineligible(array $pending): array
    {
        return [
            'eligible' => false,
            'pending_units' => array_values(array_unique($pending)),
            'message' => 'Completion requirements are still pending.',
        ];
    }
}
