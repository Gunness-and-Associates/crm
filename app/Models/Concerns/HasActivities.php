<?php

namespace App\Models\Concerns;

use App\Models\Call;
use App\Models\Document;
use App\Models\Email;
use App\Models\Meeting;
use App\Models\Note;
use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * The record side of the polymorphic activities (BACKEND_BRIEF §7.3) — any
 * Contactable (or other CRM) model can carry meetings, notes, documents,
 * calls, tasks and emails without its own link table.
 */
trait HasActivities
{
    /** @return MorphMany<Meeting, $this> */
    public function meetings(): MorphMany
    {
        return $this->morphMany(Meeting::class, 'subject');
    }

    /** @return MorphMany<Note, $this> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'subject');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'subject');
    }

    /** @return MorphMany<Call, $this> */
    public function calls(): MorphMany
    {
        return $this->morphMany(Call::class, 'subject');
    }

    /** @return MorphMany<Task, $this> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'subject');
    }

    /** @return MorphMany<Email, $this> */
    public function emails(): MorphMany
    {
        return $this->morphMany(Email::class, 'subject');
    }

    /**
     * Every activity for this record, newest first — the raw feed a
     * paginated timeline UI groups and filters by type.
     *
     * @return Collection<int, Meeting|Note|Document|Call|Task|Email>
     */
    public function activityFeed(): Collection
    {
        return $this->meetings
            ->concat($this->notes)
            ->concat($this->documents)
            ->concat($this->calls)
            ->concat($this->tasks)
            ->concat($this->emails)
            ->sortByDesc('created_at')
            ->values();
    }
}
