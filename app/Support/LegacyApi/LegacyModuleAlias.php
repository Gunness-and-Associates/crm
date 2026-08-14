<?php

namespace App\Support\LegacyApi;

use App\Exceptions\Api\ApiException;

/**
 * Resolves a legacy `/Api/V8/module/{legacyModule}` name to its v1 target, per
 * docs/contracts/api-contract.md §2.3. The map itself lives in config/legacy_api.php
 * so the whole adapter — this class, the config, the controller and its routes —
 * can be deleted together once the 133 n8n workflows have migrated to `/api/v1`.
 */
final class LegacyModuleAlias
{
    public function resolve(string $legacyModule): LegacyModuleTarget
    {
        $entry = $this->entries()[$legacyModule] ?? null;

        if ($entry === null) {
            throw ApiException::notFound("Legacy module [{$legacyModule}] is not recognised.");
        }

        if (($entry['gone'] ?? false) === true) {
            $message = is_string($entry['message'] ?? null)
                ? $entry['message']
                : "The {$legacyModule} module is not part of this system.";

            throw ApiException::gone($message);
        }

        $module = $entry['module'] ?? null;
        if (! is_string($module)) {
            throw ApiException::notFound("Legacy module [{$legacyModule}] is not yet available through this adapter.");
        }

        $vertical = $entry['vertical'] ?? null;

        return new LegacyModuleTarget($module, is_string($vertical) ? $vertical : null);
    }

    /**
     * @return array<string, array{module?: string|null, vertical?: string|null, gone?: bool, message?: string}>
     */
    private function entries(): array
    {
        /** @var array<string, array{module?: string|null, vertical?: string|null, gone?: bool, message?: string}> */
        return config('legacy_api.modules', []);
    }
}
