<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\AdBreakdown;
use App\Models\AdInsight;
use App\Models\FacebookAdAccount;
use Illuminate\Support\Carbon;

class InsightsSync
{
    private const DAILY_FIELDS = 'ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,spend,impressions,reach,clicks,inline_link_clicks,outbound_clicks,actions,video_play_actions,video_thruplay_watched_actions,video_p25_watched_actions,video_p50_watched_actions,video_p75_watched_actions,video_p95_watched_actions,video_p100_watched_actions,video_avg_time_watched_actions,date_start';

    private const AD_FIELDS = 'id,name,status,effective_status,campaign{id,name,objective},adset{id,name,daily_budget,optimization_goal,bid_strategy,targeting},creative.thumbnail_width(480).thumbnail_height(480){thumbnail_url,video_id,object_type,body,title,call_to_action_type,object_story_spec}';

    private const WITH_IMPRESSIONS = '[{"field":"impressions","operator":"GREATER_THAN","value":0}]';

    public function __construct(private FacebookGraph $graph) {}

    public function syncAccount(FacebookAdAccount $account, int $days = 30, bool $dailyOnly = false): void
    {
        $token = $account->connection->access_token;
        $act = 'act_'.$account->account_id;
        $range = json_encode([
            'since' => Carbon::today()->subDays($days - 1)->toDateString(),
            'until' => Carbon::today()->toDateString(),
        ]);

        $this->storeAds($account, $this->graph->paginate("{$act}/ads", [
            'fields' => self::AD_FIELDS,
            'filtering' => json_encode([['field' => 'effective_status', 'operator' => 'IN', 'value' => ['ACTIVE']]]),
            'limit' => 50,
        ], $token));

        // one 7-day slice at a time: fetch, save, free memory (big accounts have thousands of ads)
        for ($end = Carbon::today(); $end->gte(Carbon::today()->subDays($days - 1)); $end = $end->copy()->subDays(7)) {
            $start = $end->copy()->subDays(6);
            $floor = Carbon::today()->subDays($days - 1);
            $chunk = json_encode(['since' => ($start->lt($floor) ? $floor : $start)->toDateString(), 'until' => $end->toDateString()]);

            $rows = $this->graph->paginate("{$act}/insights", [
                'level' => 'ad',
                'time_increment' => 1,
                'time_range' => $chunk,
                'fields' => self::DAILY_FIELDS,
                'filtering' => self::WITH_IMPRESSIONS,
                'limit' => 200,
            ], $token);

            $ids = array_values(array_unique(array_column($rows, 'ad_id')));
            $known = $account->ads()->whereIn('ad_id', $ids)->pluck('ad_id')->all();
            $this->fetchAdsByIds($account, $token, array_values(array_diff($ids, $known)));

            $this->storeDaily($account, $rows);
            unset($rows, $ids, $known);
            gc_collect_cycles();
        }

        if ($dailyOnly) {
            $account->forceFill(['last_synced_at' => now()])->save();

            return;
        }

        $this->syncFrequency($account, $act, $token);
        $this->syncRankings($account, $act, $token);
        $this->syncWindows($account, $act, $token);

        foreach ([
            'age_gender' => 'age,gender',
            'hour' => 'hourly_stats_aggregated_by_advertiser_time_zone',
            'placement' => 'publisher_platform,platform_position',
            'device' => 'impression_device',
            'region' => 'region',
        ] as $kind => $breakdowns) {
            $this->syncBreakdown($account, $act, $token, $range, $kind, $breakdowns);
        }

        $account->forceFill(['last_synced_at' => now()])->save();
    }

    /** @param  list<string>  $ids */
    private function fetchAdsByIds(FacebookAdAccount $account, string $token, array $ids): void
    {
        foreach (array_chunk($ids, 50) as $chunk) {
            $urls = array_map(fn ($id) => $id.'?'.http_build_query(['fields' => self::AD_FIELDS]), $chunk);
            $this->storeAds($account, $this->graph->batch($urls, $token));
        }
    }

    /** @param  list<array<string, mixed>>  $ads */
    private function storeAds(FacebookAdAccount $account, array $ads): void
    {
        foreach ($ads as $ad) {
            $creative = $ad['creative'] ?? [];
            $spec = $creative['object_story_spec'] ?? [];

            $account->ads()->updateOrCreate(['ad_id' => $ad['id']], [
                'name' => $ad['name'] ?? null,
                'status' => $ad['status'] ?? null,
                'effective_status' => $ad['effective_status'] ?? null,
                'campaign_id' => $ad['campaign']['id'] ?? null,
                'campaign_name' => $ad['campaign']['name'] ?? null,
                'objective' => $ad['campaign']['objective'] ?? null,
                'adset_id' => $ad['adset']['id'] ?? null,
                'adset_name' => $ad['adset']['name'] ?? null,
                'thumbnail_url' => $creative['thumbnail_url'] ?? null,
                'is_video' => ! empty($creative['video_id']) || ($creative['object_type'] ?? '') === 'VIDEO',
                'creative_body' => $creative['body'] ?? ($spec['video_data']['message'] ?? $spec['link_data']['message'] ?? null),
                'creative_title' => $creative['title'] ?? ($spec['video_data']['title'] ?? $spec['link_data']['name'] ?? null),
                'cta_type' => $creative['call_to_action_type'] ?? null,
                'link_url' => $spec['link_data']['link'] ?? $spec['video_data']['call_to_action']['value']['link'] ?? null,
                'adset_daily_budget' => isset($ad['adset']['daily_budget']) ? $ad['adset']['daily_budget'] / 100 : null,
                'optimization_goal' => $ad['adset']['optimization_goal'] ?? null,
                'bid_strategy' => $ad['adset']['bid_strategy'] ?? null,
                'targeting' => $ad['adset']['targeting'] ?? null,
            ]);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function storeDaily(FacebookAdAccount $account, array $rows): void
    {
        $ads = $account->ads()->get()->keyBy('ad_id');
        $prepared = [];

        foreach ($rows as $row) {
            $ad = $ads[$row['ad_id']] ?? null;
            if (! $ad) {
                continue;
            }

            $actions = $row['actions'] ?? [];
            $plays = $this->action($row['video_play_actions'] ?? [], ['video_view']);
            $avg = $this->action($row['video_avg_time_watched_actions'] ?? [], ['video_view'], true);

            $prepared[$ad->id][] = [
                'date' => $row['date_start'],
                'data' => $this->metrics($row) + [
                    'reach' => (int) ($row['reach'] ?? 0),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'outbound_clicks' => $this->action($row['outbound_clicks'] ?? [], ['outbound_click']),
                    'landing_views' => $this->action($actions, ['landing_page_view']),
                    'leads_form' => $this->action($actions, ['onsite_conversion.lead_grouped']),
                    'leads_web' => $this->action($actions, ['offsite_conversion.fb_pixel_lead']),
                    'messages' => $this->action($actions, ['onsite_conversion.messaging_conversation_started_7d']),
                    'engagements' => $this->action($actions, ['post_engagement']),
                    'reactions' => $this->action($actions, ['post_reaction']),
                    'comments' => $this->action($actions, ['comment']),
                    'shares' => $this->action($actions, ['post']),
                    'saves' => $this->action($actions, ['onsite_conversion.post_save']),
                    'video_plays' => $plays,
                    'video_3s' => $this->action($actions, ['video_view']),
                    'thruplays' => $this->action($row['video_thruplay_watched_actions'] ?? [], ['video_view']),
                    'video_p25' => $this->action($row['video_p25_watched_actions'] ?? [], ['video_view']),
                    'video_p50' => $this->action($row['video_p50_watched_actions'] ?? [], ['video_view']),
                    'video_p75' => $this->action($row['video_p75_watched_actions'] ?? [], ['video_view']),
                    'video_p95' => $this->action($row['video_p95_watched_actions'] ?? [], ['video_view']),
                    'video_p100' => $this->action($row['video_p100_watched_actions'] ?? [], ['video_view']),
                    'watch_seconds' => $avg * $plays,
                ],
            ];
        }

        foreach ($prepared as $adId => $days) {
            $ad = $ads->firstWhere('id', $adId);
            $totals = [
                'leads' => array_sum(array_column(array_column($days, 'data'), 'leads')),
                'messages' => array_sum(array_column(array_column($days, 'data'), 'messages')),
                'link_clicks' => array_sum(array_column(array_column($days, 'data'), 'link_clicks')),
            ];
            $type = ResultType::fromGoal($ad->optimization_goal, $ad->objective) ?? ($ad->result_type ?: ResultType::infer($totals));
            $ad->update(['result_type' => $type]);

            foreach ($days as $day) {
                AdInsight::updateOrCreate(
                    ['ad_id' => $adId, 'date' => $day['date']],
                    $day['data'] + ['results' => ResultType::results($type, $day['data'])],
                );
            }
        }
    }

    private function syncFrequency(FacebookAdAccount $account, string $act, string $token): void
    {
        $ids = $account->ads()->pluck('id', 'ad_id');

        foreach (['frequency_7d' => 'last_7d', 'frequency_total' => 'maximum'] as $column => $preset) {
            $rows = $this->graph->paginate("{$act}/insights", [
                'level' => 'ad',
                'date_preset' => $preset,
                'fields' => 'ad_id,frequency',
                'filtering' => self::WITH_IMPRESSIONS,
                'limit' => 200,
            ], $token);

            foreach ($rows as $row) {
                if (isset($ids[$row['ad_id']])) {
                    Ad::whereKey($ids[$row['ad_id']])->update([$column => $row['frequency'] ?? null]);
                }
            }
        }
    }

    private function syncRankings(FacebookAdAccount $account, string $act, string $token): void
    {
        $ids = $account->ads()->pluck('id', 'ad_id');

        $rows = $this->graph->paginate("{$act}/insights", [
            'level' => 'ad',
            'date_preset' => 'last_7d',
            'fields' => 'ad_id,quality_ranking,engagement_rate_ranking,conversion_rate_ranking',
            'filtering' => self::WITH_IMPRESSIONS,
            'limit' => 200,
        ], $token);

        foreach ($rows as $row) {
            if (isset($ids[$row['ad_id']])) {
                Ad::whereKey($ids[$row['ad_id']])->update([
                    'quality_ranking' => $this->ranking($row['quality_ranking'] ?? null),
                    'engagement_ranking' => $this->ranking($row['engagement_rate_ranking'] ?? null),
                    'conversion_ranking' => $this->ranking($row['conversion_rate_ranking'] ?? null),
                ]);
            }
        }
    }

    /** Account-level reach/frequency; reach cannot be summed from daily rows. */
    private function syncWindows(FacebookAdAccount $account, string $act, string $token): void
    {
        foreach ([3, 7, 14, 30] as $days) {
            $range = json_encode([
                'since' => Carbon::yesterday()->subDays($days - 1)->toDateString(),
                'until' => Carbon::yesterday()->toDateString(),
            ]);
            $rows = $this->graph->paginate("{$act}/insights", [
                'time_range' => $range,
                'fields' => 'reach,impressions,frequency',
            ], $token);

            $row = $rows[0] ?? [];
            $account->windows()->updateOrCreate(['days' => $days], [
                'reach' => (int) ($row['reach'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'frequency' => $row['frequency'] ?? null,
            ]);
        }
    }

    private function ranking(?string $value): ?string
    {
        return $value && $value !== 'UNKNOWN' ? $value : null;
    }

    private function syncBreakdown(FacebookAdAccount $account, string $act, string $token, string $range, string $kind, string $breakdowns): void
    {
        $ids = $account->ads()->pluck('id', 'ad_id');

        $rows = $this->graph->paginate("{$act}/insights", [
            'level' => 'ad',
            'time_range' => $range,
            'breakdowns' => $breakdowns,
            'fields' => 'ad_id,spend,impressions,clicks,inline_link_clicks,actions',
            'filtering' => self::WITH_IMPRESSIONS,
            'limit' => 200,
        ], $token);

        AdBreakdown::where('kind', $kind)->whereIn('ad_id', $ids->values())->delete();

        $batch = [];
        foreach ($rows as $row) {
            if (! isset($ids[$row['ad_id']])) {
                continue;
            }

            $batch[] = [
                'ad_id' => $ids[$row['ad_id']],
                'kind' => $kind,
                'age' => $row['age'] ?? null,
                'gender' => $row['gender'] ?? null,
                'hour' => isset($row['hourly_stats_aggregated_by_advertiser_time_zone'])
                    ? (int) substr($row['hourly_stats_aggregated_by_advertiser_time_zone'], 0, 2)
                    : null,
                'platform' => $row['publisher_platform'] ?? null,
                'position' => $row['platform_position'] ?? null,
                'device' => $row['impression_device'] ?? null,
                'region' => $row['region'] ?? null,
                ...$this->metrics($row),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'landing_views' => $this->action($row['actions'] ?? [], ['landing_page_view']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($batch, 500) as $chunk) {
            AdBreakdown::insert($chunk);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{spend: float, impressions: int, link_clicks: int, leads: int}
     */
    private function metrics(array $row): array
    {
        return [
            'spend' => (float) ($row['spend'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'link_clicks' => (int) ($row['inline_link_clicks'] ?? 0),
            'leads' => $this->leads($row['actions'] ?? []),
        ];
    }

    /** @param  list<array{action_type: string, value: string}>  $actions */
    private function leads(array $actions): int
    {
        $total = $this->action($actions, ['lead']);

        return $total > 0
            ? $total
            : $this->action($actions, ['onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead']);
    }

    /**
     * @param  list<array{action_type: string, value: string}>  $actions
     * @param  list<string>  $types
     */
    private function action(array $actions, array $types, bool $float = false): int|float
    {
        $sum = 0;

        foreach ($actions as $action) {
            if (in_array($action['action_type'], $types, true)) {
                $sum += (float) $action['value'];
            }
        }

        return $float ? $sum : (int) $sum;
    }
}
