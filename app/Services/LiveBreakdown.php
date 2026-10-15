<?php

namespace App\Services;

use App\Models\FacebookAdAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Account-level breakdowns straight from Facebook for any date range (cached briefly).
 */
class LiveBreakdown
{
    private const KINDS = [
        'age_gender' => 'age,gender',
        'hour' => 'hourly_stats_aggregated_by_advertiser_time_zone',
        'placement' => 'publisher_platform,platform_position',
        'device' => 'impression_device',
        'region' => 'region',
    ];

    public function __construct(private FacebookGraph $graph) {}

    /**
     * @param  list<string>  $dims
     * @return Collection<int, array<string, mixed>>
     */
    public function get(FacebookAdAccount $account, string $kind, array $dims, Carbon $from, Carbon $to): Collection
    {
        $key = "live:{$account->id}:{$kind}:{$from->toDateString()}:{$to->toDateString()}";
        $ttl = $to->isToday() ? now()->addMinutes(10) : now()->addMinutes(60);

        $rows = Cache::remember($key, $ttl, fn () => $this->fetch($account, $kind, $from, $to));

        return collect($rows)->map(function ($row) use ($dims) {
            $out = [];
            foreach ($dims as $dim) {
                $out[$dim] = $dim === 'hour'
                    ? (int) substr($row['hourly_stats_aggregated_by_advertiser_time_zone'] ?? '0', 0, 2)
                    : ($row[$this->field($dim)] ?? null);
            }

            return $out + AdMetrics::derive([
                'spend' => $row['spend'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'link_clicks' => $row['inline_link_clicks'] ?? 0,
                'clicks' => $row['clicks'] ?? 0,
                'leads' => $this->leads($row['actions'] ?? []),
                'landing_views' => $this->sum($row['actions'] ?? [], ['landing_page_view']),
            ]);
        })->values();
    }

    /**
     * Same breakdown, but only for the given campaigns, counted in the given result type.
     *
     * @param  list<string>  $campaignIds
     * @param  list<string>  $dims
     * @return Collection<int, array<string, mixed>>
     */
    public function forCampaigns(FacebookAdAccount $account, array $campaignIds, string $kind, array $dims, Carbon $from, Carbon $to, string $rtype = 'leads'): Collection
    {
        sort($campaignIds);
        $key = 'live:c:'.md5(implode(',', $campaignIds)).":{$account->id}:{$kind}:{$rtype}:{$from->toDateString()}:{$to->toDateString()}";

        $rows = Cache::remember($key, now()->addMinutes(30), fn () => $this->graph->paginate('act_'.$account->account_id.'/insights', [
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'breakdowns' => self::KINDS[$kind],
            'fields' => 'spend,impressions,clicks,inline_link_clicks,actions',
            'filtering' => json_encode([['field' => 'campaign.id', 'operator' => 'IN', 'value' => $campaignIds]]),
            'limit' => 500,
        ], $account->connection->access_token));

        return collect($rows)->map(function ($row) use ($dims, $rtype) {
            $out = [];
            foreach ($dims as $dim) {
                $out[$dim] = $dim === 'hour'
                    ? (int) substr($row['hourly_stats_aggregated_by_advertiser_time_zone'] ?? '0', 0, 2)
                    : ($row[$this->field($dim)] ?? null);
            }
            $actions = $row['actions'] ?? [];

            return $out + AdMetrics::derive([
                'rtype' => $rtype,
                'spend' => $row['spend'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'link_clicks' => $row['inline_link_clicks'] ?? 0,
                'clicks' => $row['clicks'] ?? 0,
                'leads' => $this->leads($actions),
                'messages' => $this->sum($actions, ['onsite_conversion.messaging_conversation_started_7d']),
                'landing_views' => $this->sum($actions, ['landing_page_view']),
            ]);
        })->values();
    }

    /** @return list<array<string, mixed>> */
    private function fetch(FacebookAdAccount $account, string $kind, Carbon $from, Carbon $to): array
    {
        return $this->graph->paginate('act_'.$account->account_id.'/insights', [
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'breakdowns' => self::KINDS[$kind],
            'fields' => 'spend,impressions,clicks,inline_link_clicks,actions',
            'limit' => 500,
        ], $account->connection->access_token);
    }

    private function field(string $dim): string
    {
        return ['platform' => 'publisher_platform', 'position' => 'platform_position', 'device' => 'impression_device'][$dim] ?? $dim;
    }

    /** @param  list<array{action_type: string, value: string}>  $actions */
    private function leads(array $actions): int
    {
        $total = $this->sum($actions, ['lead']);

        return $total > 0 ? $total : $this->sum($actions, ['onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead']);
    }

    /**
     * @param  list<array{action_type: string, value: string}>  $actions
     * @param  list<string>  $types
     */
    private function sum(array $actions, array $types): int
    {
        return (int) collect($actions)->whereIn('action_type', $types)->sum('value');
    }
}
