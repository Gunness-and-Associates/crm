<?php

namespace App\Support\Email;

use App\Support\Api\ApiModuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * BACKEND_BRIEF §10: "Email templates: stored records with merge fields resolved
 * from *current* metadata, so a template keeps working after a field is
 * renamed." `{{field_name}}` placeholders are resolved against the record's
 * module's live registered fields at send time, never a snapshot taken when the
 * template was written.
 */
final class MergeFieldResolver
{
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

    public function __construct(private readonly ApiModuleRegistry $registry) {}

    public function resolve(string $text, Model $record, string $moduleKey): string
    {
        $fields = $this->registry->fields($moduleKey);

        $resolved = preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            fn (array $matches): string => $this->resolvePlaceholder($matches[1], $record, $fields),
            $text,
        );

        return $resolved ?? $text;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function resolvePlaceholder(string $name, Model $record, array $fields): string
    {
        // leads.full_name (and any Contactable module's) has no real column or
        // Eloquent accessor — Contactable::fullName() is a plain method, not
        // reachable via getAttribute(). Special-cased here so templates can still
        // use the one merge field everyone actually wants.
        if ($name === 'full_name' && method_exists($record, 'fullName')) {
            return $this->stringify($record->fullName());
        }

        $known = [...array_keys($fields), 'assigned_user_id', 'created_at', 'updated_at'];
        if (! in_array($name, $known, true)) {
            // Left as-is rather than blanked — a broken placeholder should be
            // visibly wrong in the sent email, never silently disappear.
            return "{{{$name}}}";
        }

        $type = $fields[$name]['type'] ?? null;

        return $this->format($record->getAttribute($name), is_string($type) ? $type : null);
    }

    private function format(mixed $value, ?string $type): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof Carbon) {
            return $type === 'date' ? $value->toFormattedDateString() : $value->format('M j, Y g:i A');
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => $this->stringify($item), $value));
        }

        return $this->stringify($value);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
