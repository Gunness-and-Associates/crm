<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Read-mostly log — no telephony (rule 12). Created manually or by an external system
// via the API when calling happens outside the CRM (Vapi via n8n).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->subjectable();
            $table->string('direction', 10); // inbound | outbound
            $table->dateTime('date_start');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('outcome')->nullable();
            $table->text('summary')->nullable();
            $table->string('recording_url')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
