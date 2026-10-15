<?php

namespace App\Services;

class AdMetrics
{
    /**
     * Turn raw sums into derived ratios.
     *
     * @param  array<string, mixed>|object  $row
     * @return array<string, float|int|null>
     */
    public static function derive(array|object $row): array
    {
        $r = (array) $row;
        $rawLeads = (int) ($r['leads'] ?? 0);
        $messages = (int) ($r['messages'] ?? 0);
        $rtype = $r['rtype'] ?? null;
        $costSpend = (float) ($r['spend'] ?? 0);
        $types = [];

        if ($rtype !== null) {
            // one ad: judge it on its own result type
            $results = ResultType::results($rtype, ['leads' => $rawLeads, 'messages' => $messages, 'link_clicks' => (int) ($r['link_clicks'] ?? 0), 'landing_views' => (int) ($r['landing_views'] ?? 0)]);
        } elseif (array_key_exists('spend_leads', $r)) {
            // many ads: each result type is summed on its own, the biggest spender is the headline
            foreach (['leads', 'messages', 'traffic', 'landing'] as $t) {
                $sp = (float) ($r['spend_'.$t] ?? 0);
                $rs = (int) ($r['res_'.$t] ?? 0);
                if ($sp > 0 || $rs > 0) {
                    $types[$t] = ['spend' => round($sp, 2), 'results' => $rs, 'cost' => $rs > 0 ? round($sp / $rs, 2) : null];
                }
            }
            uasort($types, fn ($a, $b) => $b['spend'] <=> $a['spend']);
            $rtype = array_key_first($types) ?? 'leads';
            $results = $types[$rtype]['results'] ?? 0;
            $costSpend = $types[$rtype]['spend'] ?? 0.0;
        } else {
            $rtype = 'leads';
            $results = $rawLeads;
        }

        $spend = (float) ($r['spend'] ?? 0);
        $impr = (int) ($r['impressions'] ?? 0);
        $link = (int) ($r['link_clicks'] ?? 0);
        $clicks = (int) ($r['clicks'] ?? 0);
        $leads = $results;
        $lpv = (int) ($r['landing_views'] ?? 0);
        $v3s = (int) ($r['video_3s'] ?? 0);
        $thru = (int) ($r['thruplays'] ?? 0);
        $plays = (int) ($r['video_plays'] ?? 0);
        $ratio = fn (float $a, float $b, int $d = 2, int $mul = 100) => $b > 0 ? round($a / $b * $mul, $d) : null;
        $social = (int) ($r['reactions'] ?? 0) + (int) ($r['comments'] ?? 0) + (int) ($r['shares'] ?? 0) + (int) ($r['saves'] ?? 0);

        return [
            'spend' => round($spend, 2),
            'impressions' => $impr,
            'link_clicks' => $link,
            'clicks' => $clicks,
            'landing_views' => $lpv,
            'leads' => $leads,
            'leads_form' => (int) ($r['leads_form'] ?? 0),
            'leads_web' => (int) ($r['leads_web'] ?? 0),
            'cpl' => $ratio($costSpend, $leads, 2, 1),
            'rtype' => $rtype,
            'rlabel' => ResultType::SHORT[$rtype] ?? 'Lid',
            'rcost' => ResultType::COST[$rtype] ?? 'Lid narxi',
            'types' => $types,
            'raw_leads' => $rawLeads,
            'messages' => $messages,
            'ctr' => $ratio($link, $impr),
            'ctr_all' => $ratio($clicks, $impr),
            'cpm' => $ratio($spend, $impr, 2, 1000),
            'cpc' => $ratio($spend, $link, 2, 1),
            'cost_lpv' => $ratio($spend, $lpv, 2, 1),
            'lpv_rate' => $lpv > 0 ? $ratio($lpv, $link, 1) : null,
            'conv' => $ratio($leads, $link),
            'lead_rate' => $lpv > 0 ? $ratio($leads, $lpv) : $ratio($leads, $link),
            'hook' => $v3s > 0 ? $ratio($v3s, $impr, 1) : null,
            'hold' => $v3s > 0 && $thru > 0 ? $ratio($thru, $v3s, 1) : null,
            'thruplays' => $thru,
            'cost_thruplay' => $ratio($spend, $thru, 3, 1),
            'video_plays' => $plays,
            'p25' => $ratio((int) ($r['video_p25'] ?? 0), $plays, 1),
            'p50' => $ratio((int) ($r['video_p50'] ?? 0), $plays, 1),
            'p75' => $ratio((int) ($r['video_p75'] ?? 0), $plays, 1),
            'p95' => $ratio((int) ($r['video_p95'] ?? 0), $plays, 1),
            'p100' => $ratio((int) ($r['video_p100'] ?? 0), $plays, 1),
            'avg_watch' => $plays > 0 ? round((float) ($r['watch_seconds'] ?? 0) / $plays, 1) : null,
            'reactions' => (int) ($r['reactions'] ?? 0),
            'comments' => (int) ($r['comments'] ?? 0),
            'shares' => (int) ($r['shares'] ?? 0),
            'saves' => (int) ($r['saves'] ?? 0),
            'social_rate' => $ratio($social, $impr, 3),
        ];
    }

    /** Select list with per-result-type spend/results; needs the `ads` table joined. */
    public static function sumSelectTyped(): string
    {
        $typed = [];
        foreach (['leads', 'messages', 'traffic', 'landing'] as $t) {
            $typed[] = "SUM(CASE WHEN ads.result_type = '$t' THEN ad_insights.spend ELSE 0 END) spend_$t";
            $typed[] = "SUM(CASE WHEN ads.result_type = '$t' THEN ad_insights.results ELSE 0 END) res_$t";
        }

        return self::sumSelect().', '.implode(', ', $typed);
    }

    /** SQL select list that sums the additive insight columns. */
    public static function sumSelect(): string
    {
        return implode(', ', array_map(
            fn ($c) => "SUM(ad_insights.$c) $c",
            ['spend', 'messages', 'impressions', 'link_clicks', 'clicks', 'landing_views', 'leads', 'leads_form', 'leads_web',
                'video_plays', 'video_3s', 'thruplays', 'video_p25', 'video_p50', 'video_p75', 'video_p95', 'video_p100',
                'watch_seconds', 'reactions', 'comments', 'shares', 'saves'],
        ));
    }
}
