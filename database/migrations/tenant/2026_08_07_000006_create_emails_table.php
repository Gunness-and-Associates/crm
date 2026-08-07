<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Email is polymorphic like every other activity; the address book itself is the
// separate EmailAddress morph (BACKEND_BRIEF §7.2), not this table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->subjectable();
            $table->string('subject_line')->nullable(); // "subject" is reserved for the morph relation
            $table->string('from_address');
            $table->json('to_addresses');
            $table->json('cc_addresses')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->string('status', 20)->default('sent'); // draft | sent | failed
            $table->dateTime('sent_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
