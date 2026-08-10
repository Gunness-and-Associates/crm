<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Referral partners (source ga_affiliate + _cstm, ~49 rows).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->contactable();

            $table->string('username')->nullable()->unique();
            $table->string('affiliate_link')->nullable();
            $table->decimal('commission', 8, 2)->nullable();
            $table->string('status', 30)->default('active');

            $table->softDeletes();
            $table->timestamps();

            $table->index(['deleted_at', 'assigned_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
