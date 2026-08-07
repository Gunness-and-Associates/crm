<?php

namespace App\Support\Acl;

use App\Support\Acl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The shared global scope (BACKEND_BRIEF §8.3): none -> no rows, owner -> only
 * assigned_user_id = me, all -> unrestricted. Applied to every model using
 * HasAcl; the API reuses this unchanged, so the interface and the API can
 * never disagree about who sees what.
 */
final class AppliesRecordAccess implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! $model instanceof Aclable) {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $level = app(Acl::class)->effective($user, $model->moduleKey(), 'view');

        match ($level) {
            AccessLevel::All => null,
            AccessLevel::Owner => $builder->where($model->qualifyColumn('assigned_user_id'), $user->id),
            AccessLevel::None, AccessLevel::NotSet => $builder->whereRaw('1 = 0'),
        };
    }
}
