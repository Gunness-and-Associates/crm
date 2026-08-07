<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->subjectable();
            $table->string('name');
            $table->string('file_path'); // via the Storage facade only
            $table->string('file_mime_type', 100)->nullable();
            $table->string('category')->nullable();
            $table->string('status', 20)->default('active'); // active | expired | draft
            $table->boolean('is_template')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('document_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('file_path');
            $table->string('file_mime_type', 100)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
        Schema::dropIfExists('documents');
    }
};
