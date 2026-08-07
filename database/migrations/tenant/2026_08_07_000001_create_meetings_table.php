<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Polymorphic activity (BACKEND_BRIEF §7.3) — morphs to any CRM record via subjectable().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->subjectable();
            $table->string('name');
            $table->string('location')->nullable();
            $table->dateTime('date_start');
            $table->dateTime('date_end')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('status', 20)->default('planned'); // planned | held | not_held
            $table->text('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
