<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Express Entry CRS/FSW calculator (source ga_assessment_request + ga_assessment_score,
// ~88 scoring fields — DATA_MODEL §2). Not a Contactable record: it is not one of the
// modules listed in BACKEND_BRIEF §7.1. Headline figures are typed columns; the full
// factor breakdown lives in `scores` json — promote a key to a column only when it
// must be filtered or sorted (via SchemaManager, not a hand-written migration).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('primary_email')->nullable();
            $table->string('phone_mobile', 50)->nullable();

            $table->string('case_type', 20)->default('crs'); // crs | fsw | combined
            $table->string('status', 20)->default('requested'); // requested|in_review|completed|sent
            $table->unsignedSmallInteger('crs_score')->nullable();
            $table->unsignedSmallInteger('fsw_score')->nullable();
            $table->string('marital_status', 30)->nullable();
            $table->string('education_level', 60)->nullable();
            $table->string('language_test_type', 30)->nullable();
            $table->unsignedTinyInteger('clb_speaking')->nullable();
            $table->unsignedTinyInteger('clb_listening')->nullable();
            $table->unsignedTinyInteger('clb_reading')->nullable();
            $table->unsignedTinyInteger('clb_writing')->nullable();
            $table->json('scores')->nullable(); // the remaining ~80 CRS/FSW factor fields

            $table->foreignUuid('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignUuid('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('assigned_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->dateTime('assessed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['deleted_at', 'assigned_user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
