<?php

namespace App\Support\Etl;

use App\Models\User;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use App\Support\Etl\Concerns\RecoversLegacyEmail;
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
    use NormalizesLegacyValues;
    use RecoversLegacyEmail;

    /** @var array<string, string>|null */
    private ?array $usernameOverrides = null;

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
        $username = $this->usernameOverrides()[$id] ?? $this->nullableString($row['user_name'] ?? null);
        $name = trim("{$firstName} {$lastName}") ?: ($username ?? $id);

        $deleted = (bool) ($row['deleted'] ?? false);
        $sourceStatus = $this->stringValue($row['status'] ?? null);

        // users.email is NOT NULL + unique — a user with no recoverable email
        // (38 of 74 in the audited source) gets an obviously-fake placeholder in
        // the reserved .invalid TLD (RFC 2606) rather than failing to migrate
        // the account at all.
        $email = $this->recoverEmail($id, 'Users') ?? sprintf('%s@migrated.invalid', $username ?? $id);

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
     * `users.username` is unique, but a handful of source rows share the same
     * user_name (e.g. two `api_user` rows, one active and one a stale
     * superseded duplicate) — without disambiguation, whichever collides
     * second on `--only=users` fails the unique constraint and never
     * migrates, silently breaking every created_by/assigned_user_id FK
     * across every other entity that references it (discovered via 256 FK
     * failures on ga_imm_can alone). The row with `deleted=0`, then the most
     * recently modified, keeps the plain username; every other row sharing
     * that username gets a short id suffix so all of them still migrate.
     *
     * @return array<string, string>
     */
    private function usernameOverrides(): array
    {
        if ($this->usernameOverrides !== null) {
            return $this->usernameOverrides;
        }

        $groups = DB::connection('legacy')->table('users')
            ->select(['id', 'user_name', 'deleted', 'date_modified'])
            ->whereNotNull('user_name')
            ->where('user_name', '!=', '')
            ->get()
            ->groupBy('user_name');

        $overrides = [];
        foreach ($groups as $username => $rows) {
            if ($rows->count() < 2) {
                continue;
            }

            $sorted = $rows->sortBy([
                ['deleted', 'asc'],
                ['date_modified', 'desc'],
            ]);
            $winner = $sorted->first();
            $winnerId = $winner === null ? null : $this->stringValue($winner->id);

            foreach ($rows as $row) {
                $rowId = $this->stringValue($row->id);
                if ($rowId !== '' && $rowId !== $winnerId) {
                    $overrides[$rowId] = sprintf('%s-%s', $this->stringValue($username), substr($rowId, 0, 8));
                }
            }
        }

        return $this->usernameOverrides = $overrides;
    }
}
