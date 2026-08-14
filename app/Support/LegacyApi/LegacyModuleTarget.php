<?php

namespace App\Support\LegacyApi;

/**
 * Where a legacy module name (§2.3) actually points: a v1 module key, plus the
 * Lead vertical it's fixed to (null for `GA_GALead`, whose vertical comes from
 * the aliased `category_c` attribute instead — see LegacyFieldAliasResolver).
 */
final readonly class LegacyModuleTarget
{
    public function __construct(
        public string $module,
        public ?string $vertical,
    ) {}
}
