<?php

namespace App\Support\Api;

/**
 * The OAuth scope grammar from docs/contracts/api-contract.md §1.1 —
 * `{module}:read`, `{module}:write`, `{module}:delete` per registered module,
 * plus the fixed scopes. Built dynamically from ApiModuleRegistry (Z-5.3) so a
 * module registered later needs no scope list update.
 */
final class ApiScopes
{
    private const ACTIONS = ['read', 'write', 'delete'];

    /**
     * @var list<string>
     */
    private const FIXED = ['metadata:read', 'studio:write', 'users:read'];

    public function __construct(private readonly ApiModuleRegistry $registry) {}

    /**
     * Passport::tokensCan() wants scope => description, for its (unused here, but
     * still validated) consent-screen display.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $scopes = [];
        foreach (self::FIXED as $fixed) {
            $scopes[$fixed] = "Access to {$fixed}.";
        }

        foreach ($this->registry->moduleKeys() as $module) {
            foreach (self::ACTIONS as $action) {
                $scope = self::for($module, $action);
                $scopes[$scope] = ucfirst($action)." access to the {$module} module.";
            }
        }

        return $scopes;
    }

    public static function for(string $module, string $action): string
    {
        return "{$module}:{$action}";
    }
}
