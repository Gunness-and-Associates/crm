<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Z-5.6 — "receive -> verify -> log raw payload -> ..." (api-contract.md Part 3):
 * every inbound ingest payload is logged here before FieldMapper touches it, so a
 * bad mapping or a rejected payload is debuggable after the fact without needing
 * to reproduce the original webhook call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source');
            $table->json('raw_payload');
            $table->json('mapped_attributes')->nullable();
            $table->string('status'); // received|processed|duplicate|rejected|failed
            $table->uuid('record_id')->nullable();
            $table->string('matched_by')->nullable(); // which dedupe rule matched, if any
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_logs');
    }
};
