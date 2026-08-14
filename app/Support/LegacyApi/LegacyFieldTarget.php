<?php

namespace App\Support\LegacyApi;

/**
 * Where a legacy field name resolves to: either a real top-level attribute, or a
 * key inside the Lead `vertical_attributes` JSON bag (api-contract.md §2.4's last
 * alias row — `own_business_bi_c`, `best_time_to_call_*_c`, ...).
 */
final readonly class LegacyFieldTarget
{
    private function __construct(
        public string $key,
        public bool $isVerticalAttribute,
    ) {}

    public static function attribute(string $name): self
    {
        return new self($name, false);
    }

    public static function verticalAttribute(string $key): self
    {
        return new self($key, true);
    }
}
