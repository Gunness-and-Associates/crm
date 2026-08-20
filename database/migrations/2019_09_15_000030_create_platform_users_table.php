<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central-database table (BACKEND_BRIEF_ZAIN.md §14 step 1). Distinct from the
// tenant-scoped `users` table — platform super-admins manage tenants, they are
// not a CRM user of any one company.
class CreatePlatformUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_users');
    }
}
