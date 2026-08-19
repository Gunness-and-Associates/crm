<?php

namespace App\Support\Etl;

/**
 * Reads a legacy SuiteCRM Studio view-def file (`custom/modules/{Module}/
 * metadata/*.php`, falling back to the stock `modules/{Module}/metadata/*.php`
 * when no custom override exists for that specific view — the same resolution
 * SuiteCRM itself uses). These are plain PHP files assigning a nested array
 * literal to a fixed variable name (`$listViewDefs`, `$searchdefs`,
 * `$viewdefs`) — no class definitions, no function calls, no side effects —
 * so reading one is a plain `include` in an isolated scope, never a real
 * "execute untrusted code" concern: this is trusted local project source
 * (the legacy CRM install being migrated), not third-party or user-supplied
 * content.
 */
final class LegacyViewDefReader
{
    public function __construct(private readonly ?string $root) {}

    /**
     * @return array<string, mixed>|null
     */
    public function listView(string $module): ?array
    {
        $vars = $this->includeCustomThenStock($module, 'listviewdefs.php');

        return $this->dig($vars, 'listViewDefs', $module);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function searchView(string $module): ?array
    {
        $vars = $this->includeCustomThenStock($module, 'searchdefs.php');

        return $this->dig($vars, 'searchdefs', $module, 'layout', 'advanced_search');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detailView(string $module): ?array
    {
        $vars = $this->includeCustomThenStock($module, 'detailviewdefs.php');

        return $this->dig($vars, 'viewdefs', $module, 'DetailView', 'panels');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function editView(string $module): ?array
    {
        $vars = $this->includeCustomThenStock($module, 'editviewdefs.php');

        return $this->dig($vars, 'viewdefs', $module, 'EditView', 'panels');
    }

    /**
     * Walks a chain of string keys through nested arrays, returning null the
     * moment any level isn't actually an array (rather than chained offset
     * access on a still-mixed value, which phpstan can't follow).
     *
     * @param  array<string, mixed>  $vars
     * @return array<string, mixed>|null
     */
    private function dig(array $vars, string ...$keys): ?array
    {
        $current = $vars;
        foreach ($keys as $key) {
            $next = $current[$key] ?? null;
            if (! is_array($next)) {
                return null;
            }
            $current = $next;
        }

        return $this->arrayOrNull($current);
    }

    /**
     * @return array<string, mixed>
     */
    private function includeCustomThenStock(string $module, string $file): array
    {
        if ($this->root === null) {
            return [];
        }

        $custom = "{$this->root}/custom/modules/{$module}/metadata/{$file}";
        $stock = "{$this->root}/modules/{$module}/metadata/{$file}";
        $path = is_file($custom) ? $custom : (is_file($stock) ? $stock : null);

        return $path === null ? [] : $this->includeAndCapture($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function includeAndCapture(string $path): array
    {
        // Every stock SuiteCRM file guards against direct web access with
        // `if (!defined('sugarEntry') || !sugarEntry) die(...)` -- a real
        // SuiteCRM request always defines this before including any module
        // file, so defining it here is the intended way to consume these
        // files programmatically, not a bypass of anything. Without it,
        // that die() would kill this whole process (uncatchable).
        if (! defined('sugarEntry')) {
            define('sugarEntry', true);
        }

        $capture = (function () use ($path): array {
            include $path;

            return get_defined_vars();
        })();

        return $capture;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
