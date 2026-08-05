<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BACKEND_BRIEF §5.1/§6: system-field protection + the full changes audit shape.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('is_custom');
        });

        Schema::table('fields', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('is_custom');
        });

        Schema::table('changes', function (Blueprint $table): void {
            $table->string('target_module', 60)->nullable()->after('kind');
            $table->string('target_field', 60)->nullable()->after('target_module');
            $table->string('snapshot_path', 500)->nullable()->after('ddl');
            $table->foreignUuid('reviewer_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->string('review_note', 500)->nullable()->after('reviewer_id');
        });
    }

    public function down(): void
    {
        Schema::table('changes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewer_id');
            $table->dropColumn(['target_module', 'target_field', 'snapshot_path', 'review_note']);
        });

        Schema::table('fields', function (Blueprint $table): void {
            $table->dropColumn('is_system');
        });

        Schema::table('modules', function (Blueprint $table): void {
            $table->dropColumn('is_system');
        });
    }
};
