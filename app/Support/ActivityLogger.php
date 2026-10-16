<?php

namespace App\Support;

use App\Models\ActivityLog;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * Capture safe, explicitly selected fields before a model is updated.
     * Do not include secrets such as passwords, tokens, or recovery codes.
     */
    public static function snapshot(Model $model, array $fields): array
    {
        $snapshot = [];

        foreach ($fields as $field) {
            $snapshot[$field] = self::normalize($model->getAttribute($field));
        }

        return $snapshot;
    }

    /**
     * Compare a pre-update snapshot with the persisted model and return only changed fields.
     * Each item is shaped as [field => ['from' => old, 'to' => new]].
     */
    public static function changes(Model $model, array $snapshot): array
    {
        $changes = [];

        foreach ($snapshot as $field => $before) {
            $after = self::normalize($model->getAttribute((string) $field));

            if ($before !== $after) {
                $changes[(string) $field] = [
                    'from' => $before,
                    'to' => $after,
                ];
            }
        }

        return $changes;
    }

    /** @param array<string, mixed> $metadata
     *  @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    public static function withChanges(array $metadata, array $changes): array
    {
        if ($changes !== []) {
            $metadata['changes'] = $changes;
        }

        return $metadata;
    }

    public static function record(
        string $event,
        string $description,
        ?int $institutionId = null,
        array $metadata = []
    ): void {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'institution_id' => $institutionId,
            'event' => $event,
            'description' => $description,
            'metadata' => $metadata ?: null,
        ]);
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item);
            }

            return $normalized;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }
}
