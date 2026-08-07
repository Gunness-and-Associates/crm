<?php

namespace App\Models\Concerns;

use App\Models\EmailAddress;
use App\Models\EmailAddressRelation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Links a Contactable record to the EmailAddress morph (BACKEND_BRIEF §7.2).
 * Always attach through attachEmailAddress() — never emailAddresses()->attach()/
 * sync() directly, since those are raw inserts that bypass EmailAddressRelation's
 * model events, which is where primary_email is kept in sync.
 *
 * @mixin Model
 */
trait HasEmailAddresses
{
    /** @return MorphToMany<EmailAddress, $this, EmailAddressRelation> */
    public function emailAddresses(): MorphToMany
    {
        return $this->morphToMany(EmailAddress::class, 'related', 'email_address_relations')
            ->using(EmailAddressRelation::class)
            ->withPivot(['is_primary', 'is_reply_to'])
            ->withTimestamps();
    }

    public function attachEmailAddress(string $rawEmail, bool $primary = false): EmailAddress
    {
        $email = strtolower(trim($rawEmail));
        $address = EmailAddress::query()->firstOrCreate(['email' => $email]);

        if ($primary) {
            $this->emailAddresses()
                ->wherePivot('is_primary', true)
                ->get()
                ->each(fn (EmailAddress $e) => $e->pivot?->update(['is_primary' => false]));
        }

        $existing = $this->emailAddresses()->wherePivot('email_address_id', $address->id)->first();

        if ($existing !== null) {
            $existing->pivot?->update(['is_primary' => $primary]);
        } else {
            EmailAddressRelation::query()->create([
                'related_type' => $this->getMorphClass(),
                'related_id' => $this->getKey(),
                'email_address_id' => $address->id,
                'is_primary' => $primary,
            ]);
        }

        return $address;
    }

    public function primaryEmailAddress(): ?EmailAddress
    {
        return $this->emailAddresses()->wherePivot('is_primary', true)->first();
    }
}
