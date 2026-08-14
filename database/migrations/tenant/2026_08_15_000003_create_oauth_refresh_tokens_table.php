<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Z-5.3: Laravel Passport's own migration, relocated into tenant/ per rule 2.
// Unmodified — no user-table reference here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_refresh_tokens', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->char('access_token_id', 80)->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
    }
};
