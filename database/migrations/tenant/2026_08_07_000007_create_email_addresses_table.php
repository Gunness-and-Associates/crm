<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Email is not a column on any record (BACKEND_BRIEF §7.2) — it lives here, linked
// through the polymorphic email_address_relations pivot, with primary_email
// denormalised onto the owning Contactable record for list display and search.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->boolean('is_invalid')->default(false);
            $table->boolean('opted_out')->default(false);
            $table->timestamps();
        });

        Schema::create('email_address_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('email_address_id')->constrained('email_addresses')->cascadeOnDelete();
            $table->uuidMorphs('related');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_reply_to')->default(false);
            $table->timestamps();

            $table->unique(['email_address_id', 'related_type', 'related_id'], 'email_address_relations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_address_relations');
        Schema::dropIfExists('email_addresses');
    }
};
