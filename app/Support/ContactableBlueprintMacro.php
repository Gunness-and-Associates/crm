<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * Registers Blueprint::contactable() — the one shared definition of the
 * Contactable base columns (BACKEND_BRIEF §7.1), so every lead-type entity
 * migration calls one macro instead of repeating ~25 columns.
 */
final class ContactableBlueprintMacro
{
    public static function register(): void
    {
        Blueprint::macro('contactable', function (): void {
            /** @var Blueprint $this */
            $this->string('salutation', 20)->nullable();
            $this->string('first_name', 100)->nullable();
            $this->string('last_name', 100)->nullable();
            $this->string('title', 100)->nullable();
            $this->string('department', 100)->nullable();
            $this->text('description')->nullable();

            $this->boolean('do_not_call')->default(false);
            $this->string('phone_home', 50)->nullable();
            $this->string('phone_mobile', 50)->nullable();
            $this->string('phone_work', 50)->nullable();
            $this->string('phone_other', 50)->nullable();
            $this->string('phone_fax', 50)->nullable();
            $this->string('whatsapp_number', 50)->nullable();

            foreach (['primary_address', 'alt_address'] as $prefix) {
                $this->string("{$prefix}_street", 255)->nullable();
                $this->string("{$prefix}_city", 100)->nullable();
                $this->string("{$prefix}_state", 100)->nullable();
                $this->string("{$prefix}_postalcode", 20)->nullable();
                $this->string("{$prefix}_country", 100)->nullable();
            }

            // Consent tracking (PIPEDA/GDPR) — keep for every contactable record.
            $this->text('lawful_basis')->nullable();
            $this->date('date_reviewed')->nullable();
            $this->string('lawful_basis_source', 255)->nullable();

            // Denormalised for list display, search and dedup. Kept in sync with
            // EmailAddress by a model observer — see Z-2.2.
            $this->string('primary_email', 255)->nullable()->index();

            $this->foreignUuid('assigned_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $this->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $this->foreignUuid('modified_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }
}
