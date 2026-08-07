<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ACL engine (BACKEND_BRIEF §8): module x action x named access level, additive across
// roles. Not spatie/laravel-permission's binary model — SuiteCRM's All/Owner/None levels
// don't map onto a permission grid, so this is a small purpose-built matrix instead.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('role_module_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('module_key', 60);

            // Each: 'all' | 'owner' | 'none' | 'not_set'. Named levels, never integers —
            // an ETL that meets a source integer must map it here and throw on the unmapped.
            foreach (['view', 'list', 'edit', 'delete', 'import', 'export', 'mass_update'] as $action) {
                $table->string($action, 10)->default('none');
            }

            $table->timestamps();
            $table->unique(['role_id', 'module_key']);
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('role_module_permissions');
        Schema::dropIfExists('roles');
    }
};
