<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Employer/recruiter directory (source ga_companies, ~21,014 rows). Uses the
// Contactable base per BACKEND_BRIEF §7.1 (a company is modelled on the Person
// base, matching the source — it carries a contact person's name/phone).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->contactable();

            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('lmia', 20)->nullable();
            $table->string('jobpostlink')->nullable();
            $table->string('jobtitle')->nullable();
            $table->string('employees', 20)->nullable(); // banded, e.g. "1-10"
            $table->string('company_type', 60)->nullable();
            $table->string('company_contact_status', 60)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('website')->nullable();
            $table->string('contact_person_name')->nullable();
            $table->string('contact_person_phone', 50)->nullable();
            $table->boolean('pnp_program')->default(false);
            $table->boolean('resume_submitted')->default(false);
            $table->boolean('hot_lead')->default(false);
            $table->boolean('warm_lead')->default(false);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['deleted_at', 'assigned_user_id']);
            $table->index('company_contact_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
