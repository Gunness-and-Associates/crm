<?php

namespace App\Support\Etl;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Load order position 1 (BACKEND_BRIEF §13) — no dependencies, everything else
 * references users via assigned_user_id/created_by/etc.
 */
final class UserTransformer implements LegacyTransformer
{
    public function key(): string
    {
        return 'users';
    }

    public function modelClass(): string
    {
        return User::class;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')->table('users');

        if ($fromId !== null) {
            $query->where('id', '>=', $fromId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function transform(array $row): ?array
    {
        $id = $this->stringValue($row['id'] ?? null);
        if ($id === '') {
            return null;
        }

        $firstName = trim($this->stringValue($row['first_name'] ?? null));
        $lastName = trim($this->stringValue($row['last_name'] ?? null));
        $username = $this->nullableString($row['user_name'] ?? null);
        $name = trim("{$firstName} {$lastName}") ?: ($username ?? $id);

        $deleted = (bool) ($row['deleted'] ?? false);
        $sourceStatus = $this->stringValue($row['status'] ?? null);

        // users.email is NOT NULL + unique — a user with no recoverable email
        // (38 of 74 in the audited source) gets an obviously-fake placeholder in
        // the reserved .invalid TLD (RFC 2606) rather than failing to migrate
        // the account at all.
        $email = $this->recoverEmail($id) ?? sprintf('%s@migrated.invalid', $username ?? $id);

        return [
            'id' => $id,
            'name' => $name,
            'username' => $username,
            'email' => $email,
            // The SuiteCRM password hash format is not bcrypt-compatible and
            // can't be verified against — force a reset rather than carrying
            // over a hash nobody can use or validate.
            'password' => Hash::make(Str::random(40)),
            'is_admin' => (bool) ($row['is_admin'] ?? false),
            'status' => ! $deleted && $sourceStatus === 'Active' ? 'active' : 'inactive',
            'reports_to_id' => $this->nullableString($row['reports_to_id'] ?? null),
            'locale' => 'en',
            'timezone' => 'UTC',
        ];
    }

    /**
     * BACKEND_BRIEF §13's email recovery query, scoped to bean_module='Users'.
     */
    private function recoverEmail(string $userId): ?string
    {
        $email = DB::connection('legacy')
            ->table('email_addr_bean_rel')
            ->join('email_addresses', 'email_addresses.id', '=', 'email_addr_bean_rel.email_address_id')
            ->where('email_addr_bean_rel.bean_id', $userId)
            ->where('email_addr_bean_rel.bean_module', 'Users')
            ->where('email_addr_bean_rel.deleted', 0)
            ->where('email_addresses.deleted', 0)
            ->orderByDesc('email_addr_bean_rel.primary_address')
            ->value('email_addresses.email_address');

        return $this->nullableString($email);
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $string = $this->stringValue($value);

        return $string === '' ? null : $string;
    }
}
