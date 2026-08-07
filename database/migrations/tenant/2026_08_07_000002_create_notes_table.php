<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->subjectable();
            $table->string('name')->nullable();
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable(); // via the Storage facade only
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
