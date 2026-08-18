<?php

namespace App\Support\Etl;

/**
 * Config for one of the ~25 legacy GA_* lead modules (BACKEND_BRIEF §13/§7.4,
 * DATA_MODEL.md §2) — every module shares the same Contactable base and the
 * same target (`leads`), differing only in vertical assignment, which column
 * (if any) holds a stage-like status, hot/warm flags, and which extra columns
 * become `vertical_attributes`. One reusable transformer (LeadModuleTransformer)
 * is configured per module rather than ~25 near-duplicate classes.
 */
final class LeadModuleSpec
{
    /**
     * @param  list<string>  $verticalAttributeColumns  row keys (base or cstm,
     *                                                  verbatim) to keep in
     *                                                  vertical_attributes,
     *                                                  keyed by the same name
     *                                                  with a trailing `_c`
     *                                                  stripped.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $table,
        public readonly ?string $cstmTable,
        public readonly string $emailBeanModule,
        public readonly ?string $fixedVertical,
        public readonly ?string $verticalDeriveColumn,
        public readonly ?string $stageColumn,
        public readonly ?string $hotLeadColumn = null,
        public readonly ?string $warmLeadColumn = null,
        public readonly ?string $declineReasonColumn = null,
        public readonly ?string $lastContactedAtColumn = null,
        public readonly array $verticalAttributeColumns = [],
    ) {}
}
