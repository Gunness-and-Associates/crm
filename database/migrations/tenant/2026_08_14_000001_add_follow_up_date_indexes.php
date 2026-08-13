<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Z-4.4 index review: leads.next_follow_up_at and clients.next_action_at are filtered
// by range (overdue / due today) on every dashboard load (DashboardService) — both were
// missing an index, forcing a full table scan on these two lookups.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->index('next_follow_up_at');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->index('next_action_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['next_follow_up_at']);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['next_action_at']);
        });
    }
};
