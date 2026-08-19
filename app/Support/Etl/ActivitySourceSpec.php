<?php

namespace App\Support\Etl;

use Illuminate\Database\Eloquent\Model;

/**
 * One source of subject-linkage for a legacy activity table (notes, calls,
 * meetings, documents). BACKEND_BRIEF/DATA_MODEL §3: the ~154 per-module
 * junction tables (`ga_companies_notes_c`, ...) collapse into the new
 * polymorphic (subject_type, subject_id) columns — this describes how to
 * read one of them, or the handful of activities linked the "stock" SuiteCRM
 * way via a direct `parent_type`/`parent_id` on the activity table itself.
 *
 * Junction table id-column names follow a fixed SuiteCRM convention verified
 * against the real schema: `{junctionTable minus trailing _c}{moduleTable}_ida`
 * and `{junctionTable minus trailing _c}{activityTable}_idb` — computed
 * rather than hand-listed for every one of the ~20 real junction tables.
 */
final class ActivitySourceSpec
{
    /**
     * @param  class-string<Model>  $subjectClass
     */
    private function __construct(
        public readonly ?string $junctionTable,
        public readonly ?string $moduleTable,
        public readonly ?string $directParentType,
        public readonly string $subjectClass,
    ) {}

    /**
     * @param  class-string<Model>  $subjectClass
     */
    public static function viaJunction(string $junctionTable, string $moduleTable, string $subjectClass): self
    {
        return new self($junctionTable, $moduleTable, null, $subjectClass);
    }

    /**
     * @param  class-string<Model>  $subjectClass
     */
    public static function viaParentType(string $parentType, string $subjectClass): self
    {
        return new self(null, null, $parentType, $subjectClass);
    }

    public function isJunction(): bool
    {
        return $this->junctionTable !== null;
    }

    public function moduleIdColumn(string $activityTable): string
    {
        return $this->junctionBase().$this->moduleTable.'_ida';
    }

    public function activityIdColumn(string $activityTable): string
    {
        return $this->junctionBase().$activityTable.'_idb';
    }

    private function junctionBase(): string
    {
        return str_ends_with((string) $this->junctionTable, '_c')
            ? substr((string) $this->junctionTable, 0, -2)
            : (string) $this->junctionTable;
    }
}
