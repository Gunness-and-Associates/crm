<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The email_address_relations pivot. This is the ONE place `primary_email` is
 * kept in sync with the EmailAddress morph (BACKEND_BRIEF §7.2) — every write
 * goes through here (via HasEmailAddresses::attachEmailAddress(), never a raw
 * attach()/sync() call, since those bypass Eloquent model events entirely).
 */
class EmailAddressRelation extends MorphPivot
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'email_address_relations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'is_reply_to' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $pivot) => $pivot->syncPrimaryEmailOntoOwner());
        static::deleted(fn (self $pivot) => $pivot->syncPrimaryEmailOntoOwner());
    }

    private function syncPrimaryEmailOntoOwner(): void
    {
        $relatedType = $this->getAttribute('related_type');
        $relatedId = $this->getAttribute('related_id');
        if (! is_string($relatedType) || $relatedType === '') {
            return;
        }

        $ownerClass = Relation::getMorphedModel($relatedType) ?? $relatedType;
        if (! is_subclass_of($ownerClass, Model::class)) {
            return;
        }

        // An internal consistency operation, not a user-facing read — must not be
        // subject to record-visibility ACL (e.g. no acting user in a console/queue
        // context would otherwise silently skip the sync).
        $owner = $ownerClass::query()->withoutGlobalScopes()->find($relatedId);
        if (! $owner instanceof Model || ! array_key_exists('primary_email', $owner->getAttributes())) {
            return;
        }

        $primary = self::query()
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->where('is_primary', true)
            ->with('emailAddress')
            ->first();

        $owner->forceFill(['primary_email' => $primary?->emailAddress?->email])->saveQuietly();
    }

    /** @return BelongsTo<EmailAddress, $this> */
    public function emailAddress(): BelongsTo
    {
        return $this->belongsTo(EmailAddress::class);
    }
}
