<?php

namespace App\Support\Ingest;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;

/**
 * §21 Q4: "Assignment rule for inbound leads (round-robin or by vertical) ->
 * Round-robin across active sales users." The candidate pool is every active user
 * holding a "Sales Representatives*" role (docs/reference/roles.php's naming
 * convention covers per-branch variants like "Sales Representatives - Dildar").
 * The last-assigned user id is persisted via Settings so the rotation survives
 * across requests/queue workers.
 */
final class LeadAssigner
{
    private const CURSOR_KEY = 'ingest.assignment.leads.last_user_id';

    public function __construct(private readonly Settings $settings) {}

    public function assign(Model $record): void
    {
        $user = $this->nextUser();
        if ($user !== null) {
            $record->setAttribute('assigned_user_id', $user->id);
            $record->save();
        }
    }

    private function nextUser(): ?User
    {
        $candidates = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('name', 'like', 'Sales Representatives%'))
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $lastId = $this->settings->get(self::CURSOR_KEY);
        $index = 0;

        if (is_string($lastId)) {
            $position = $candidates->search(fn (User $user): bool => $user->id === $lastId);
            if ($position !== false) {
                $index = ($position + 1) % $candidates->count();
            }
        }

        /** @var User $next */
        $next = $candidates[$index];
        $this->settings->set(self::CURSOR_KEY, $next->id);

        return $next;
    }
}
