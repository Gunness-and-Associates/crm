<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Z-6.2 ETL discovery: ga_assessment_score.education holds the full IRCC
// education-category description ("Two-year program at a university,
// college, trade or technical school, or other institute", up to 140 chars),
// not a short code — the original varchar(60) truncated 6,463 of 9,158 real
// rows on insert. ga_assessment_request.highest_level_education is short
// (max 13 chars observed) and unaffected; widening is safe for both sources.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table): void {
            $table->string('education_level', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table): void {
            $table->string('education_level', 60)->nullable()->change();
        });
    }
};
