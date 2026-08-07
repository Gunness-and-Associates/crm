<?php

namespace App\Support\Acl;

/**
 * Implemented by any model using HasAcl, so AppliesRecordAccess can call
 * moduleKey() with a real type instead of probing an untyped trait method.
 */
interface Aclable
{
    public function moduleKey(): string;
}
