<?php

namespace App\Http\Controllers;

use App\Models\CompetencyProgress;
use App\Models\CompetencyTask;
use App\Models\CompetencyUnitReview;
use App\Models\EnrollmentApplication;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineCompetencyProgressSyncController extends Controller
{
    private const STATUSES = [
        'submitted_output_correct',
        'competent',
        'not_yet_competent',
        'not_assessed',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mutations' => ['required', 'array', 'min:1', 'max:25'],
            'mutations.*.mutation_id' => ['required', 'uuid'],
            'mutations.*.enrollment_id' => ['required', 'integer', 'min:1'],
            'mutations.*.task_id' => ['required', 'integer', 'min:1'],
            'mutations.*.status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
            'mutations.*.base_status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
            'mutations.*.base_updated_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ]);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user && $user->account_status === 'active' && $user->hasRole('administrator', 'focal_person'), 403);

        $results = [];
        foreach ($validated['mutations'] as $mutation) {
            $results[] = $this->processOne($user, $mutation);
        }

        return response()->json(['results' => $results]);
    }

    /** @param array<string, mixed> $mutation
     *  @return array<string, mixed>
     */
    private function processOne(User $user, array $mutation): array
    {
        $payload = [
            'operation' => 'competency_progress',
            'enrollment_id' => (int) $mutation['enrollment_id'],
            'task_id' => (int) $mutation['task_id'],
            'status' => (string) $mutation['status'],
            'base_status' => (string) $mutation['base_status'],
            'base_updated_at' => $mutation['base_updated_at'] ?? null,
        ];
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $mutationId = (string) $mutation['mutation_id'];

        try {
            return DB::transaction(function () use ($user, $payload, $payloadHash, $mutationId): array {
                DB::table('offline_sync_mutations')->insert([
                    'user_id' => $user->id,
                    'mutation_id' => $mutationId,
                    'operation' => 'competency_progress',
                    'payload_hash' => $payloadHash,
                    'result' => null,
                    'processed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $result = $this->applyMutation($user, $mutationId, $payload);

                DB::table('offline_sync_mutations')
                    ->where('user_id', $user->id)
                    ->where('mutation_id', $mutationId)
                    ->update([
                        'result' => json_encode($result, JSON_THROW_ON_ERROR),
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);

                return $result;
            });
        } catch (QueryException $exception) {
            // A concurrent retry may hit the unique (user_id, mutation_id) index.
            // Return the original result only if this mutation ID has the same payload.
            $existing = DB::table('offline_sync_mutations')
                ->where('user_id', $user->id)
                ->where('mutation_id', $mutationId)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                return [
                    'mutation_id' => $mutationId,
                    'status' => 'rejected',
                    'message' => 'This mutation ID was already used for a different change. Review the record online before retrying.',
                ];
            }

            $originalResult = $existing->result ? json_decode((string) $existing->result, true) : null;
            if (is_array($originalResult)) {
                return $originalResult;
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $mutation
     *  @return array<string, mixed>
     */
    private function applyMutation(User $user, string $mutationId, array $mutation): array
    {
        $baseResult = ['mutation_id' => $mutationId];
        $application = EnrollmentApplication::query()
            ->whereKey($mutation['enrollment_id'])
            ->where('application_status', 'confirmed')
            ->first();

        if (! $application) {
            return $baseResult + [
                'status' => 'rejected',
                'message' => 'The confirmed enrollment could not be found. Review the record online.',
            ];
        }

        if (! $user->hasRole('administrator')) {
            $isAssigned = $user->institutions()
                ->where('institutions.id', $application->institution_id)
                ->exists();

            if (! $isAssigned) {
                return $baseResult + [
                    'status' => 'rejected',
                    'message' => 'You no longer have access to this institution. Review the record online.',
                ];
            }
        }

        $task = CompetencyTask::query()->with('competencyUnit')->find($mutation['task_id']);
        if (! $task || ! $task->competencyUnit || (int) $task->competencyUnit->course_id !== (int) $application->course_id) {
            return $baseResult + [
                'status' => 'rejected',
                'message' => 'The competency task no longer belongs to this trainee course.',
            ];
        }

        $review = CompetencyUnitReview::query()
            ->where('enrollment_application_id', $application->id)
            ->where('competency_unit_id', $task->competency_unit_id)
            ->first();

        if ($review?->is_locked) {
            return $baseResult + [
                'status' => 'rejected',
                'message' => 'This competency unit is verified and locked. No offline change was applied.',
            ];
        }

        $current = CompetencyProgress::query()
            ->where('enrollment_application_id', $application->id)
            ->where('competency_task_id', $task->id)
            ->first();

        // A retry whose desired state is already present is safe to acknowledge.
        if ($current && $current->status === $mutation['status']) {
            return $baseResult + [
                'status' => 'already_applied',
                'server_status' => $current->status,
                'server_updated_at' => $current->updated_at?->format('Y-m-d H:i:s'),
                'message' => 'The requested status is already saved on the server.',
            ];
        }

        $baseUpdatedAt = $mutation['base_updated_at'];
        $currentUpdatedAt = $current?->updated_at?->format('Y-m-d H:i:s');
        $baseMatches = $current
            ? ($current->status === $mutation['base_status']
                && $baseUpdatedAt !== null
                && $currentUpdatedAt === $baseUpdatedAt)
            : ($mutation['base_status'] === 'not_assessed' && $baseUpdatedAt === null);

        if (! $baseMatches) {
            return $baseResult + [
                'status' => 'conflict',
                'server_status' => $current?->status ?? 'not_assessed',
                'server_updated_at' => $currentUpdatedAt,
                'message' => 'This task changed on the server while you were offline. Review the current status before re-applying your change.',
            ];
        }

        $progress = CompetencyProgress::query()->updateOrCreate(
            [
                'enrollment_application_id' => $application->id,
                'competency_task_id' => $task->id,
            ],
            [
                'status' => $mutation['status'],
                'assessed_by' => $user->id,
                'assessed_at' => now(),
            ]
        );

        return $baseResult + [
            'status' => 'synced',
            'server_status' => $progress->status,
            'server_updated_at' => $progress->updated_at?->format('Y-m-d H:i:s'),
            'message' => 'Competency status synchronized successfully.',
        ];
    }
}
