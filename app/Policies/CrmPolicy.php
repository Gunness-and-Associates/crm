<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Acl;
use App\Support\Acl\AccessLevel;
use Illuminate\Database\Eloquent\Model;

/**
 * The policy layer of the three-layer ACL enforcement (BACKEND_BRIEF §8.3):
 * checks "can I do this action?" against the same Acl::effective() the query
 * scope and (later) the API use. Extend this for every CRM entity — nothing
 * here needs a per-entity override to work.
 *
 * Note on 404 vs 403: this policy only answers "may I act on this record?";
 * returning false and letting route-model-binding fail to find an
 * out-of-scope record (via AppliesRecordAccess) is what produces a 404
 * instead of a 403 for another user's Owner-scoped record.
 */
abstract class CrmPolicy
{
    abstract protected function moduleKey(): string;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'list');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allowsRecord($user, $model, 'view');
    }

    public function create(User $user): bool
    {
        // SuiteCRM's ACL has no separate "create" action — edit covers both.
        return $this->allows($user, 'edit');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allowsRecord($user, $model, 'edit');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allowsRecord($user, $model, 'delete');
    }

    public function import(User $user): bool
    {
        return $this->allows($user, 'import');
    }

    public function export(User $user): bool
    {
        return $this->allows($user, 'export');
    }

    public function massUpdate(User $user): bool
    {
        return $this->allows($user, 'mass_update');
    }

    private function allows(User $user, string $action): bool
    {
        return app(Acl::class)->effective($user, $this->moduleKey(), $action) !== AccessLevel::None;
    }

    private function allowsRecord(User $user, Model $model, string $action): bool
    {
        $level = app(Acl::class)->effective($user, $this->moduleKey(), $action);

        return match ($level) {
            AccessLevel::All => true,
            AccessLevel::Owner => $model->getAttribute('assigned_user_id') === $user->id,
            AccessLevel::None, AccessLevel::NotSet => false,
        };
    }
}
