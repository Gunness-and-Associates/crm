<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Z-5.3: docs/contracts/api-contract.md §1.6 — "Default 600 requests per minute per
// client, configurable per client."
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->unsignedInteger('rate_limit_per_minute')->nullable()->after('revoked');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->dropColumn('rate_limit_per_minute');
        });
    }
};
