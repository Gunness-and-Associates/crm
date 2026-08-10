<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Post-conversion client lifecycle (source ga_clients + ga_clientdevelopment2/3 +
// ga_imm_client, ~325 rows). The document checklist, payment schedule and family
// members the frontend spec describes are a later phase's screens on top of this
// base, not a physical column here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->contactable();

            $table->string('client_status', 60)->nullable();
            $table->string('case_type', 60)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('dob')->nullable();
            $table->string('marital_status', 30)->nullable();
            $table->string('employment_status', 60)->nullable();
            $table->string('highest_education_level', 100)->nullable();
            $table->string('english_language_level', 30)->nullable();
            $table->string('lead_source')->nullable();
            $table->string('hear_about_us')->nullable();
            $table->string('current_status_in_canada', 100)->nullable();
            $table->string('interested_programs')->nullable();
            $table->boolean('worth_money')->default(false);
            $table->unsignedTinyInteger('work_experience_year')->nullable();
            $table->boolean('refused_a_visa')->default(false);
            $table->boolean('have_relative_canada')->default(false);
            $table->boolean('ever_visited_canada')->default(false);
            $table->text('briefly_describe_issue')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->decimal('retainer_amount', 12, 2)->nullable();
            $table->string('fee_status', 30)->nullable();
            $table->dateTime('next_action_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['deleted_at', 'assigned_user_id']);
            $table->index('client_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
