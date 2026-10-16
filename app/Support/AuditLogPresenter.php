<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Rendering-safe labels and metadata formatting for the administrator audit log.
 * Static methods avoid relying on Volt component closures being Blade variables.
 */
class AuditLogPresenter
{
    public static function moduleForEvent(string $event): string
    {
        return match (true) {
            Str::startsWith($event, ['user_logged_in', 'user_logged_out', 'login', 'logout']) => 'Authentication',
            Str::startsWith($event, ['enrollment', 'learner_document', 'learner_']) => 'Enrollment Monitoring',
            Str::startsWith($event, ['attendance']) => 'Attendance Management',
            Str::startsWith($event, ['competency', 'competency_unit', 'competency_task']) => 'Competency & Progress',
            Str::startsWith($event, ['certificate', 'certification']) => 'Certification Management',
            Str::startsWith($event, ['announcement']) => 'Announcement Management',
            Str::startsWith($event, ['report']) => 'Reports & Oversight',
            Str::startsWith($event, ['course', 'batch', 'institution', 'user_account']) => 'System Monitoring',
            default => 'General',
        };
    }

    public static function auditValue(?array $metadata, array $keys): string
    {
        if (! is_array($metadata)) {
            return '';
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $metadata)) {
                return self::formatValue($metadata[$key]);
            }
        }

        return '';
    }

    public static function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * Prepare field-level change rows stored by ActivityLogger.
     * Values are shortened for readability in the list; full values remain in metadata/export.
     */
    public static function changeEntries(?array $metadata): array
    {
        $changes = is_array($metadata) && is_array($metadata['changes'] ?? null)
            ? $metadata['changes']
            : [];
        $entries = [];

        foreach ($changes as $field => $change) {
            if (! is_array($change)
                || (! array_key_exists('from', $change) && ! array_key_exists('to', $change))) {
                continue;
            }

            $entries[] = [
                'field' => Str::headline(str_replace('_', ' ', (string) $field)),
                'from' => Str::limit(self::formatValue($change['from'] ?? null), 300),
                'to' => Str::limit(self::formatValue($change['to'] ?? null), 300),
            ];
        }

        return $entries;
    }
}
