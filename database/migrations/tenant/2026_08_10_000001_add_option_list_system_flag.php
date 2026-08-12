<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Z-3.2: frozen option lists (BACKEND_BRIEF open question 9 — lead_vertical,
// lead_stage) cannot have items added or removed via OptionListManager.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('option_lists', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('option_lists', function (Blueprint $table): void {
            $table->dropColumn('is_system');
        });
    }
};
