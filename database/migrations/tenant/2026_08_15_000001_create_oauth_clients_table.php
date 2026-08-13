<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Z-5.3: Laravel Passport's own migration, relocated into tenant/ per rule 2 — Passport
// 13 does not auto-load its migrations, it expects the app to publish and own them.
// Adapted from vendor/laravel/passport/database/migrations/2016_06_01_000004_
// create_oauth_clients_table.php: oauth_clients.id is already a uuid, but the vendor
// migration's `owner` morph defaults to a bigint owner_id — incompatible with this
// app's char(36) UUID users.id (contract §1.1: "a client carries a user identity, its
// owner" — this is how record-level ACL applies to client-credentials calls too).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('revoked');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }
};
