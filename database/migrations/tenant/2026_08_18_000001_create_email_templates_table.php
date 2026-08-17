<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Z-5.8 — BACKEND_BRIEF §10: "Stored records with merge fields resolved from
 * *current* metadata, so a template keeps working after a field is renamed."
 * `module_key` is nullable: a template scoped to one module (its merge fields are
 * validated against that module's fields in the interface) or usable anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('module_key')->nullable();
            $table->string('subject');
            $table->longText('body_html');
            $table->uuid('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
