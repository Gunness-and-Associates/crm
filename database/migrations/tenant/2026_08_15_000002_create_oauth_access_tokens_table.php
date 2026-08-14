<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Z-5.3: adapted from vendor/laravel/passport/database/migrations/2016_06_01_000002_
// create_oauth_access_tokens_table.php — the upstream migration's user_id is
// foreignId() (unsigned bigint), incompatible with this app's char(36) uuid users.id
// (HasUuids). Changed to foreignUuid() to match.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_access_tokens', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignUuid('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_access_tokens');
    }
};
