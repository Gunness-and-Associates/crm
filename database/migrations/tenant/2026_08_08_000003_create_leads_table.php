<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Consolidates 23 source GA_* lead modules (BACKEND_BRIEF §7.4, DATA_MODEL §2). vertical
// and stage are varchar backed by option lists — never a MySQL ENUM, so an administrator
// can add a value with no DDL. Vertical-specific answers live in vertical_attributes;
// promote a key to a real column only when it must be filtered/sorted, via SchemaManager.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->contactable();

            $table->string('vertical', 100)->nullable();
            $table->string('stage', 100)->default('new');
            $table->json('vertical_attributes')->nullable();

            $table->boolean('hot_lead')->default(false);
            $table->boolean('warm_lead')->default(false);
            $table->string('source')->nullable();
            $table->string('decline_reason')->nullable();
            $table->dateTime('last_contacted_at')->nullable();
            $table->dateTime('next_follow_up_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['deleted_at', 'assigned_user_id']);
            $table->index('vertical');
            $table->index('stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
