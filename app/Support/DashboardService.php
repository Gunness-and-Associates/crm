<?php

namespace App\Support;

use App\Enums\LeadStage;
use App\Models\Client;
use App\Models\Lead;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregation queries behind the v1 dashboard widgets (Z-4.3). Every query runs
 * through the normal Eloquent global scope — AppliesRecordAccess — so results are
 * automatically permission-scoped to the signed-in user; nothing here bypasses it.
 * Cached per user for 60 seconds (BACKEND_BRIEF §16).
 *
 * "Calls to make" = a lead's follow-up falls today (actionable now). "Attention
 * needed" = a lead's or client's follow-up date has already passed (missed,
 * needs escalation) — the two are deliberately mutually exclusive.
 */
final class DashboardService
{
    private const TTL_SECONDS = 60;

    /**
     * @return list<array{vertical: string, hot: int, warm: int}>
     */
    public function hotAndWarmByVertical(): array
    {
        return array_values(Cache::remember($this->cacheKey('hot_warm_by_vertical'), self::TTL_SECONDS, function (): array {
            return Lead::query()
                ->selectRaw('vertical, SUM(hot_lead) as hot, SUM(warm_lead) as warm')
                ->whereNotNull('vertical')
                ->groupBy('vertical')
                ->get()
                ->map(fn (Lead $row): array => [
                    'vertical' => $this->str($row->getRawOriginal('vertical')),
                    'hot' => $this->int($row->getAttribute('hot')),
                    'warm' => $this->int($row->getAttribute('warm')),
                ])
                ->values()
                ->all();
        }));
    }

    /**
     * @return list<array{stage: string, count: int}>
     */
    public function pipelineByStage(): array
    {
        return Cache::remember($this->cacheKey('pipeline_by_stage'), self::TTL_SECONDS, function (): array {
            $counts = [];
            Lead::query()
                ->selectRaw('stage, COUNT(*) as total')
                ->groupBy('stage')
                ->get()
                ->each(function (Lead $row) use (&$counts): void {
                    $counts[$this->str($row->getRawOriginal('stage'))] = $this->int($row->getAttribute('total'));
                });

            return array_map(
                fn (LeadStage $stage): array => ['stage' => $stage->value, 'count' => $counts[$stage->value] ?? 0],
                LeadStage::cases(),
            );
        });
    }

    /**
     * @return list<array{id: string, full_name: string, phone_mobile: string|null, due_at: string}>
     */
    public function callsToMake(int $limit = 10): array
    {
        return array_values(Cache::remember($this->cacheKey("calls_to_make:{$limit}"), self::TTL_SECONDS, function () use ($limit): array {
            return Lead::query()
                ->whereNotNull('next_follow_up_at')
                ->whereDate('next_follow_up_at', now()->toDateString())
                ->orderBy('next_follow_up_at')
                ->limit($limit)
                ->get()
                ->map(function (Lead $lead): ?array {
                    $dueAt = $lead->next_follow_up_at;
                    if ($dueAt === null) {
                        return null;
                    }

                    return [
                        'id' => $lead->id,
                        'full_name' => $lead->fullName(),
                        'phone_mobile' => $this->nullableStr($lead->getAttribute('phone_mobile')),
                        'due_at' => $dueAt->toIso8601String(),
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }));
    }

    /**
     * @return list<array{module: string, id: string, full_name: string, due_at: string}>
     */
    public function attentionNeeded(int $limit = 10): array
    {
        return array_values(Cache::remember($this->cacheKey("attention_needed:{$limit}"), self::TTL_SECONDS, function () use ($limit): array {
            $overdue = now()->startOfDay();

            $leads = Lead::query()
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', $overdue)
                ->get()
                ->map(function (Lead $lead): ?array {
                    $dueAt = $lead->next_follow_up_at;
                    if ($dueAt === null) {
                        return null;
                    }

                    return ['module' => 'leads', 'id' => $lead->id, 'full_name' => $lead->fullName(), 'due_at' => $dueAt->toIso8601String()];
                })
                ->filter();

            $clients = Client::query()
                ->whereNotNull('next_action_at')
                ->where('next_action_at', '<', $overdue)
                ->get()
                ->map(function (Client $client): ?array {
                    $dueAt = $client->next_action_at;
                    if ($dueAt === null) {
                        return null;
                    }

                    return ['module' => 'clients', 'id' => $client->id, 'full_name' => $client->fullName(), 'due_at' => $dueAt->toIso8601String()];
                })
                ->filter();

            return $leads->concat($clients)
                ->sortBy('due_at')
                ->take($limit)
                ->values()
                ->all();
        }));
    }

    private function cacheKey(string $key): string
    {
        $userId = Auth::id();

        return 'dashboard:'.(is_string($userId) ? $userId : 'guest').":{$key}";
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function nullableStr(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
