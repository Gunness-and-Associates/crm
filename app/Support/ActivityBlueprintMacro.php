<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * Registers Blueprint::subjectable() — the polymorphic (subject_type, subject_id)
 * pair every activity morphs to (BACKEND_BRIEF §7.3). One definition, reused by
 * meetings/notes/documents/calls/tasks/emails, instead of six hand-repeated pairs.
 */
final class ActivityBlueprintMacro
{
    public static function register(): void
    {
        Blueprint::macro('subjectable', function (): void {
            /** @var Blueprint $this */
            $this->uuidMorphs('subject');
            $this->foreignUuid('assigned_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $this->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }
}
