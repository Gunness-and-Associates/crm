<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// HQ Learning Hub course-sales pipeline (source ga_hq_students, ~548 rows).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->contactable();

            $table->string('get_started')->nullable();
            $table->string('status', 60)->nullable();
            $table->string('how_hear')->nullable();
            $table->boolean('hot_lead')->default(false);
            $table->boolean('warm_lead')->default(false);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['deleted_at', 'assigned_user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
