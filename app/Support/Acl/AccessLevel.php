<?php

namespace App\Support\Acl;

/**
 * The named access levels (BACKEND_BRIEF §8.1) — never integers in the app.
 * `NotSet` means "no opinion, defer" and is excluded before resolving the
 * most-permissive level across a user's roles.
 */
enum AccessLevel: string
{
    case All = 'all';
    case Owner = 'owner';
    case None = 'none';
    case NotSet = 'not_set';

    /**
     * Higher rank = more permissive. Used to pick the most permissive level
     * across a user's roles (BACKEND_BRIEF §8.2).
     */
    public function rank(): int
    {
        return match ($this) {
            self::All => 3,
            self::Owner => 2,
            self::None => 1,
            self::NotSet => 0,
        };
    }

    public static function mostPermissive(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }

    /**
     * Map a source system's integer access level using a caller-supplied table
     * (the real integers are read from the source `acl_actions`/`acl_roles_actions`
     * during the ETL — see STUDIO_API_RBAC appendix A2, not guessed here). Throws
     * on anything unmapped rather than defaulting to a permissive value
     * (BACKEND_BRIEF §8.1).
     *
     * @param  array<int, self>  $map
     */
    public static function fromLegacyInt(int $value, array $map): self
    {
        return $map[$value] ?? throw new \InvalidArgumentException("Unmapped legacy access level [{$value}].");
    }
}
