<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model half of the Contactable base (BACKEND_BRIEF §7.1) — the column
 * definitions live in the `contactable()` Blueprint macro. Email is
 * denormalised onto `primary_email` and kept in sync with the EmailAddress
 * morph by a model observer added in Z-2.2; this trait does not touch it.
 */
trait Contactable
{
    /**
     * @return array<string, string>
     */
    protected function contactableCasts(): array
    {
        return [
            'do_not_call' => 'boolean',
            'date_reviewed' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function modifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

    public function fullName(): string
    {
        return trim(sprintf('%s %s', (string) $this->getAttribute('first_name'), (string) $this->getAttribute('last_name')));
    }
}
