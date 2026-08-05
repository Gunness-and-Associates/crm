<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Metadata registry (BACKEND_BRIEF §5.1 / STUDIO_API_RBAC §1.2). UNPREFIXED table names
// (modules/fields/... not tenant_*) — the whole database becomes a tenant DB at Phase 8.
// Drives schema (SchemaManager), UI (DynamicResource), permissions and the REST API.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();                 // e.g. 'leads'
            $table->string('label');
            $table->string('table_name')->nullable();        // backing table (null until created)
            $table->string('base_type', 20)->default('generic'); // person | company | generic
            $table->string('icon')->nullable();
            $table->boolean('is_custom')->default(false);    // Studio-created vs shipped
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('option_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('option_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('option_list_id')->constrained('option_lists')->cascadeOnDelete();
            $table->string('value');                         // stored value
            $table->string('label');                         // displayed label (first-char cap only)
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['option_list_id', 'value']);
        });

        Schema::create('fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('module_id')->constrained('modules')->cascadeOnDelete();
            $table->string('name');                          // column/attribute name
            $table->string('type', 20);                      // field-types.json key
            $table->string('label_key');                     // generated LBL_* key
            $table->string('storage', 10)->default('column'); // column | json

            // Verified SuiteCRM flags (STUDIO_API_RBAC appendix A1).
            $table->boolean('required')->default(false);
            $table->text('default_value')->nullable();
            $table->json('validation')->nullable();
            $table->boolean('audited')->default(false);
            $table->boolean('filterable')->default(false);
            $table->boolean('sortable')->default(false);
            $table->boolean('mass_update')->default(false);
            $table->boolean('duplicate_merge')->default(false);
            $table->boolean('reportable')->default(true);
            $table->boolean('importable')->default(true);
            $table->text('help')->nullable();
            $table->text('comments')->nullable();

            // Typed extras replacing SuiteCRM ext1..ext4.
            $table->unsignedInteger('max_length')->nullable();
            $table->unsignedTinyInteger('precision')->nullable();
            $table->unsignedTinyInteger('scale')->nullable();
            $table->foreignUuid('option_list_id')->nullable()->constrained('option_lists')->nullOnDelete();
            $table->foreignUuid('related_module_id')->nullable()->constrained('modules')->nullOnDelete();
            $table->string('related_display_field')->nullable();

            $table->boolean('is_custom')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['module_id', 'name']);
        });

        Schema::create('layouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('module_id')->constrained('modules')->cascadeOnDelete();
            $table->string('view', 10);                      // list | detail | edit | search
            $table->json('definition');                      // layout.schema.json shape
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->index(['module_id', 'view']);
        });

        Schema::create('changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind');                          // field.created, layout.published, ...
            $table->json('payload')->nullable();             // before/after
            $table->string('status', 20)->default('applied'); // requested|approved|applied|rolled_back
            $table->text('ddl')->nullable();                 // applied DDL, for audit + rollback
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changes');
        Schema::dropIfExists('layouts');
        Schema::dropIfExists('fields');
        Schema::dropIfExists('option_items');
        Schema::dropIfExists('option_lists');
        Schema::dropIfExists('modules');
    }
};
