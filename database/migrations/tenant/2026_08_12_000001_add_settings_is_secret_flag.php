<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Z-4.1: encrypted secrets in the settings store (SMTP password, integration keys).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->boolean('is_secret')->default(false)->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('is_secret');
        });
    }
};
