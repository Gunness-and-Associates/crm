<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Simple subscriber list (source ga_newsletter_subscriber, ~2,023 rows). The consent
// panel is mostly the Contactable base (lawful_basis/date_reviewed/lawful_basis_source);
// only the unsubscribe side is specific to this entity.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->contactable();

            $table->string('status', 30)->default('subscribed'); // subscribed | unsubscribed
            $table->string('source')->nullable();
            $table->string('referred_by')->nullable();
            $table->dateTime('opted_out_at')->nullable();
            $table->string('unsubscribe_reason')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['deleted_at', 'assigned_user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
