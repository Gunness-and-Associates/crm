<?php

namespace App\Support\SchemaManager;

/**
 * A request to add, modify or delete a field. No side effects — SchemaManager::plan()
 * turns this into a ChangePlan; SchemaManager::apply() executes an already-validated plan.
 */
final class FieldChangeRequest
{
    /**
     * @param  'add'|'modify'|'delete'  $action
     * @param  array<string, mixed>  $options  length, precision, scale, default, required, help,
     *                                         comments, option_list_id, related_module_id,
     *                                         related_display_field, audited, filterable, sortable,
     *                                         mass_update, duplicate_merge, reportable, importable
     */
    public function __construct(
        public readonly string $action,
        public readonly string $moduleKey,
        public readonly string $name,
        public readonly ?string $type = null,
        public readonly array $options = [],
        public readonly bool $confirmLossy = false,
    ) {}

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
