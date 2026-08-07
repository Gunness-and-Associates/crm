<?php

namespace App\Support\Acl;

use Illuminate\Database\Eloquent\Model;

/**
 * Opt a model into the ACL engine: registers the shared AppliesRecordAccess
 * scope and provides its module key. Defaults the module key to the table
 * name, matching how modules are registered in the metadata registry.
 *
 * @mixin Model
 */
trait HasAcl
{
    protected static function bootHasAcl(): void
    {
        static::addGlobalScope(new AppliesRecordAccess);
    }

    public function moduleKey(): string
    {
        return $this->getTable();
    }
}
