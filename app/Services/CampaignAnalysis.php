<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\FacebookAdAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Which age, gender, audience and videos give cheap results" for a chosen group of campaigns.
 */
class CampaignAnalysis
{
    private const GENDERS = ['male' => 'Erkak', 'female' => 'Ayol', 'unknown' => "Noma'lum"];

    public function __construct(private AnalyticsQuery $q, private LiveBreakdown $live, private FacebookGraph $graph) {}

    /**
     * Daily budget and bid strategy of campaigns (CBO budgets live on the campaign, not the ad set).
     *
     * @param  list<string>  $ids
     * @return array<string, array{name: string, daily: float|null, lifetime: float|null, bid: string|null, status: string|null}>
     */
    private function campaignBudgets(FacebookAdAccount $account, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return Cache::remember('camp-budgets:'.md5(implode(',', $ids)), now()->addMinutes(30), function () use ($account, $ids) {
            $out = [];
            $token = $account->connection->access_token;

            foreach (array_chunk($ids, 50) as $chunk) {
                $urls = array_map(fn ($id) => $id.'?'.http_build_query(['fields' => 'name,daily_budget,lifetime_budget,bid_strategy,effective_status']), $chunk);
                foreach ($this->graph->batch($urls, $token) as $c) {
                    $out[$c['id']] = [
                        'name' => $c['name'] ?? '',
                        'daily' => isset($c['daily_budget']) ? $c['daily_budget'] / 100 : null,
                        'lifetime' => isset($c['lifetime_budget']) ? $c['lifetime_budget'] / 100 : null,
                        'bid' => $c['bid_strategy'] ?? null,
                        'status' => $c['effective_status'] ?? null,
                    ];
                }
            }

            return $out;
        });
    }

    /**
     * Campaigns of the account that spent money in the range, grouped by a short "family" name.
     *
     * @return Collection<int, array{key: string, spend: float, campaigns: int}>
     */
    public function families(FacebookAdAccount $account, DateRange $range): Collection
    {
        return $this->q->campaigns($range->from, $range->to, $account->id)
            ->groupBy(fn ($c) => $this->family($c['name']))
            ->map(fn ($g, $key) => ['key' => $key, 'spend' => $g->sum(fn ($c) => $c['m']['spend']), 'campaigns' => $g->count()])
            ->sortByDesc('spend')->values();
    }

    /** Substring match, plus typo tolerance for single words (asvijitel ~ asvujitel). */
    public function matches(string $name, string $keyword): bool
    {
        $name = mb_strtolower($name);
        $keyword = mb_strtolower(trim($keyword));

        if ($keyword === '' || str_contains($name, $keyword)) {
            return $keyword !== '';
        }

        if (mb_strlen($keyword) < 5 || str_contains($keyword, ' ')) {
            return false;
        }

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (abs(mb_strlen($word) - mb_strlen($keyword)) <= 2 && levenshtein($word, $keyword) <= 2) {
                return true;
            }
        }

        return false;
    }

    public function family(string $name): string
    {
        $head = trim((string) preg_split('/[\/|]/u', $name)[0]);
        $head = trim((string) preg_replace('/[\d\$\s\-–]+$/u', '', $head));

        return mb_strtolower($head ?: $name);
    }

    /**
     * @param  list<string>  $keywords  case-insensitive parts of campaign names
     * @return array<string, mixed>
     */
    public function build(FacebookAdAccount $account, array $keywords, DateRange $range): array
    {
        $keywords = array_values(array_filter(array_map(fn ($k) => mb_strtolower(trim($k)), $keywords)));

        $campaigns = $this->q->campaigns($range->from, $range->to, $account->id)
            ->filter(fn ($c) => $keywords && collect($keywords)->contains(fn ($k) => $this->matches($c['name'], $k)))
            ->values();

        $campaignIds = $campaigns->pluck('id')->filter()->values()->all();
        $adIds = Ad::where('facebook_ad_account_id', $account->id)->whereIn('campaign_id', $campaignIds)->pluck('id')->all();
        $ads = $campaignIds ? $this->q->ads($range->from, $range->to, $account->id, $adIds) : collect();

        $type = $ads->groupBy(fn ($r) => $r['m']['rtype'])->map(fn ($g) => $g->sum(fn ($r) => $r['m']['spend']))->sortDesc()->keys()->first() ?? 'leads';
        $spend = $ads->sum(fn ($r) => $r['m']['spend']);
        $results = $ads->sum(fn ($r) => $r['m']['leads']);
        $cost = $results > 0 ? round($spend / $results, 2) : null;

        // thresholds scale with the volume: a small campaign still deserves an answer
        $minCell = max(3, min(10, intdiv((int) $results, 30)));
        $minVol = max(5, min(20, (int) round($results / 20)));
        $minAud = max(5, min(30, (int) round($results / 10)));

        $ageGender = collect();
        $error = null;
        if ($campaignIds) {
            try {
                $ageGender = $this->live->forCampaigns($account, $campaignIds, 'age_gender', ['age', 'gender'], $range->from, $range->to, $type)
                    ->filter(fn ($r) => strtolower((string) $r['age']) !== 'unknown' && ($r['gender'] ?? '') !== 'unknown')->values();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $ageGender = $ageGender->map(fn ($r) => ['label' => (self::GENDERS[$r['gender']] ?? $r['gender']).', '.$r['age']] + $r)->values();
        $audiences = $this->audiences($ads, $cost, $minAud);

        // only ads with real volume are ranked, a $2 test with 5 leads proves nothing
        $videos = $ads->filter(fn ($r) => $r['ad']->is_video && $r['m']['leads'] >= $minVol && $r['m']['cpl'] !== null)->sortBy(fn ($r) => $r['m']['cpl'])->values();
        $worst = $ads->filter(fn ($r) => $r['ad']->is_video && $r['m']['leads'] >= 3 && $r['m']['spend'] >= 10 && $r['m']['cpl'] !== null)->sortByDesc(fn ($r) => $r['m']['cpl'])->values();
        $topAds = $ads->filter(fn ($r) => $r['m']['leads'] >= $minVol && $r['m']['cpl'] !== null)->sortBy(fn ($r) => $r['m']['cpl'])->values();

        $extra = [];
        if ($campaignIds) {
            foreach (['placement' => ['platform', 'position'], 'hour' => ['hour'], 'region' => ['region'], 'device' => ['device']] as $kind => $dims) {
                try {
                    $extra[$kind] = $this->live->forCampaigns($account, $campaignIds, $kind, $dims, $range->from, $range->to, $type)->filter(fn ($r) => $r['spend'] > 0)->values();
                } catch (\Throwable) {
                    $extra[$kind] = collect();
                }
            }
        }
        $extra['placement'] = ($extra['placement'] ?? collect())->map(fn ($r) => ['label' => ucfirst($r['platform'] ?? '?').' · '.str_replace('_', ' ', $r['position'] ?? '?')] + $r);
        $extra['hour'] = ($extra['hour'] ?? collect())->map(fn ($r) => ['label' => sprintf('%02d:00', $r['hour'])] + $r)->sortBy('hour')->values();
        $extra['region'] = ($extra['region'] ?? collect())->map(fn ($r) => ['label' => $r['region'] ?? '?'] + $r);
        $extra['device'] = ($extra['device'] ?? collect())->map(fn ($r) => ['label' => str_replace('_', ' ', $r['device'] ?? '?')] + $r);

        return [
            'keywords' => $keywords,
            'campaigns' => $campaigns,
            'type' => $type,
            'spend' => round($spend, 2),
            'results' => $results,
            'cost' => $cost,
            'ads' => $ads,
            'ageGender' => $ageGender,
            'genders' => $this->rollup($ageGender, 'gender', fn ($v) => self::GENDERS[$v] ?? $v),
            'ages' => $this->rollup($ageGender, 'age', fn ($v) => $v),
            'audiences' => $audiences,
            'bestVideos' => $videos->take(8),
            'worstVideos' => $worst->take(4),
            'insights' => $this->insights($ageGender, $audiences, $videos, $cost, $minCell, $minAud),
            'extra' => $extra,
            'topAds' => $topAds,
            'brief' => $this->brief($account, $type, $cost, $campaigns, $ads, $ageGender, $extra, $audiences, $topAds, $minCell, $this->safeBudgets($account, $campaignIds)),
            'error' => $error,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function safeBudgets(FacebookAdAccount $account, array $ids): array
    {
        try {
            return $this->campaignBudgets($account, $ids);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Everything needed to launch a new ad in one place (also as copy-paste text).
     *
     * @param  Collection<int, array<string, mixed>>  $campaigns
     * @param  Collection<int, array<string, mixed>>  $ads
     * @param  Collection<int, array<string, mixed>>  $ageGender
     * @param  array<string, Collection<int, array<string, mixed>>>  $extra
     * @param  Collection<int, array<string, mixed>>  $audiences
     * @param  Collection<int, array<string, mixed>>  $topAds
     * @return array{sections: list<array{title: string, lines: list<string>}>, text: string}
     */
    private function brief(FacebookAdAccount $account, string $type, ?float $cost, Collection $campaigns, Collection $ads, Collection $ageGender, array $extra, Collection $audiences, Collection $topAds, int $minCell, array $budgets = []): array
    {
        $sections = [];
        $money = fn ($v) => $v === null ? '—' : '$'.number_format((float) $v, 2);
        $best = fn (Collection $rows, int $min, int $n = 3) => $rows->filter(fn ($r) => ($r['cpl'] ?? null) !== null && $r['leads'] >= $min)->sortBy('cpl')->take($n);
        $fmt = fn ($r) => "{$r['label']} ({$money($r['cpl'])}, {$r['leads']} ta)";

        // audience: age range and gender
        $lines = [];
        $ages = $this->rollup($ageGender, 'age', fn ($v) => $v)->filter(fn ($r) => $r['leads'] >= $minCell && $r['cpl'] !== null && $cost && $r['cpl'] <= $cost * 1.15);
        if ($ages->isNotEmpty()) {
            $from = $ages->map(fn ($r) => (int) $r['age'])->min();
            $toRaw = $ages->map(fn ($r) => $r['age'])->sort()->last();
            $to = str_contains($toRaw, '+') ? '65+' : (int) substr($toRaw, -2);
            $lines[] = "Yosh: {$from}–{$to} (narxi o'rtachadan oshmaydigan guruhlar)";
        }
        $genders = $this->rollup($ageGender, 'gender', fn ($v) => self::GENDERS[$v] ?? $v)->filter(fn ($r) => $r['leads'] >= $minCell && $r['cpl'] !== null)->sortBy('cpl');
        if ($genders->isNotEmpty()) {
            $total = max(1, $genders->sum('leads'));
            $lines[] = 'Jins: '.$genders->map(fn ($r) => "{$r['label']} {$money($r['cpl'])} (".round($r['leads'] / $total * 100).'% lid)')->implode('; ');
        }
        $top = $best($ageGender, $minCell, 3);
        if ($top->isNotEmpty()) {
            $lines[] = 'Eng arzon guruhlar: '.$top->map($fmt)->implode('; ');
        }
        $targeting = $audiences->first(fn ($a) => $a['cost'] !== null && $a['results'] >= 1);
        if ($targeting) {
            $lines[] = "Eng yaxshi adset sozlamasi: {$targeting['targeting']}";
        }
        $sections[] = ['title' => 'Auditoriya', 'lines' => $lines];

        // where and when
        $lines = [];
        foreach (['placement' => 'Joylashuv', 'region' => 'Hudud', 'device' => 'Qurilma'] as $kind => $title) {
            $rows = $extra[$kind] ?? collect();
            $b = $best($rows, max(2, intdiv($minCell, 2)), 3);
            if ($b->isNotEmpty()) {
                $lines[] = "{$title}: ".$b->map($fmt)->implode('; ');
            }
        }
        $hours = $best($extra['hour'] ?? collect(), 2, 5)->sortBy('hour');
        if ($hours->isNotEmpty()) {
            $lines[] = 'Soatlar (eng arzon): '.$hours->map(fn ($r) => "{$r['label']} ({$money($r['cpl'])})")->implode(', ').' (kichik namuna, taxminiy)';
        }
        $sections[] = ['title' => 'Joy va vaqt', 'lines' => $lines];

        // creatives
        $lines = [];
        foreach ($topAds->take(3) as $r) {
            $ad = $r['ad'];
            $m = $r['m'];
            $kind = $ad->is_video ? 'video' : 'rasm';
            $extraM = $ad->is_video ? ", hook {$m['hook']}%, hold {$m['hold']}%" : '';
            $lines[] = "{$ad->name} ({$kind}): {$money($m['cpl'])}, {$m['leads']} ta{$extraM}";
            if ($ad->creative_title) {
                $lines[] = '   Sarlavha: '.$ad->creative_title;
            }
            if ($ad->creative_body) {
                $lines[] = '   Matn: '.mb_substr(trim(preg_replace('/\s+/', ' ', $ad->creative_body)), 0, 220);
            }
            $cta = array_filter([$ad->cta_type ? "Tugma: {$ad->cta_type}" : null, $ad->link_url ? "Havola: {$ad->link_url}" : null]);
            if ($cta) {
                $lines[] = '   '.implode(' | ', $cta);
            }
        }
        $sections[] = ['title' => 'Eng yaxshi kreativlar', 'lines' => $lines];

        // structure and budget
        $lines = [];
        $bestCampaign = $campaigns->filter(fn ($c) => $c['m']['cpl'] !== null && $c['m']['leads'] >= max(5, $minCell))->sortBy(fn ($c) => $c['m']['cpl'])->first();
        if ($bestCampaign) {
            $lines[] = "Eng arzon kampaniya: {$bestCampaign['name']} ({$money($bestCampaign['m']['cpl'])}, {$bestCampaign['m']['leads']} ta)";
        }
        if ($bestCampaign && ($b = $budgets[$bestCampaign['id']] ?? null)) {
            $parts = array_filter([
                $b['daily'] ? 'kunlik '.$money($b['daily']) : null,
                $b['lifetime'] ? 'umumiy '.$money($b['lifetime']) : null,
                $b['bid'] ? "stavka: {$b['bid']}" : null,
            ]);
            $lines[] = 'Kampaniya byudjeti (CBO): '.implode(', ', $parts ?: ['kampaniya darajasida belgilanmagan (adset darajasida)']);
        }
        $adsets = $ads->map(fn ($r) => $r['ad'])->unique('adset_id');
        $budgets = $adsets->pluck('adset_daily_budget')->filter()->map(fn ($v) => (float) $v)->sort()->values();
        if ($budgets->isNotEmpty()) {
            $lines[] = 'Adset kunlik byudjeti: '.$money($budgets->min()).' – '.$money($budgets->max()).' (o\'rtacha '.$money($budgets->median()).')';
        }
        foreach (['optimization_goal' => 'Optimallashtirish', 'bid_strategy' => 'Stavka strategiyasi', 'objective' => 'Maqsad'] as $col => $title) {
            $mode = $ads->map(fn ($r) => $r['ad']->{$col})->filter()->countBy()->sortDesc()->keys()->first();
            if ($mode) {
                $lines[] = "{$title}: {$mode}";
            }
        }
        $lines[] = 'Natija turi: '.(ResultType::LABELS[$type] ?? $type)." · jami o'rtacha narx: {$money($cost)}";
        $sections[] = ['title' => 'Tuzilma va byudjet', 'lines' => $lines];

        $sections = array_values(array_filter($sections, fn ($s) => $s['lines']));
        $text = collect($sections)->map(fn ($s) => $s['title'].":\n".collect($s['lines'])->map(fn ($l) => str_starts_with($l, '   ') ? $l : '• '.$l)->implode("\n"))->implode("\n\n");

        return ['sections' => $sections, 'text' => $text];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function rollup(Collection $rows, string $dim, callable $label): Collection
    {
        return $rows->groupBy($dim)->map(fn ($g, $key) => ['label' => $label($key), $dim => $key] + AdMetrics::derive([
            'rtype' => 'leads',
            'spend' => $g->sum('spend'),
            'impressions' => $g->sum('impressions'),
            'link_clicks' => $g->sum('link_clicks'),
            'leads' => $g->sum('leads'),
        ]))->sortBy($dim)->values();
    }

    /**
     * Ad sets are the audiences: name, short targeting summary, spend and cost.
     *
     * @param  Collection<int, array<string, mixed>>  $ads
     * @return Collection<int, array<string, mixed>>
     */
    private function audiences(Collection $ads, ?float $campaignCost, int $minAud = 30): Collection
    {
        return $ads->groupBy(fn ($r) => $r['ad']->adset_id ?: $r['ad']->adset_name)->map(function ($g) use ($campaignCost, $minAud) {
            $first = $g->first()['ad'];
            $spend = $g->sum(fn ($r) => $r['m']['spend']);
            $results = $g->sum(fn ($r) => $r['m']['leads']);
            $cost = $results > 0 ? round($spend / $results, 2) : null;

            return [
                'name' => $first->adset_name ?: '(nomsiz)',
                'campaign' => $first->campaign_name,
                'targeting' => $this->targeting($first->targeting ?? []),
                'ads' => $g->count(),
                'spend' => round($spend, 2),
                'results' => $results,
                'cost' => $cost,
                'level' => ($cost === null || $results < $minAud || ! $campaignCost) ? 'idle' : ($cost <= $campaignCost * 0.85 ? 'good' : ($cost >= $campaignCost * 1.25 ? 'bad' : 'ok')),
            ];
        })->sortBy(fn ($a) => ($a['results'] >= $minAud && $a['cost'] !== null) ? $a['cost'] : PHP_INT_MAX - $a['results'])->values();
    }

    /** @param  array<string, mixed>  $t */
    private function targeting(array $t): string
    {
        if (! $t) {
            return '—';
        }

        $parts = [];
        if (isset($t['age_min'], $t['age_max'])) {
            $parts[] = "{$t['age_min']}–{$t['age_max']} yosh";
        }
        $genders = array_values(array_intersect($t['genders'] ?? [], [1, 2]));
        if (count($genders) === 1) {
            $parts[] = [1 => 'faqat erkak', 2 => 'faqat ayol'][$genders[0]];
        }
        foreach ($t['flexible_spec'] ?? [] as $spec) {
            foreach (['interests', 'behaviors', 'work_positions', 'life_events'] as $k) {
                foreach (array_slice($spec[$k] ?? [], 0, 3) as $i) {
                    $parts[] = $i['name'] ?? '';
                }
            }
        }
        foreach (array_slice($t['custom_audiences'] ?? [], 0, 2) as $c) {
            $parts[] = 'auditoriya: '.($c['name'] ?? '?');
        }
        foreach (array_slice($t['geo_locations']['countries'] ?? [], 0, 3) as $c) {
            $parts[] = $c;
        }
        if (! empty($t['targeting_automation']['advantage_audience'])) {
            $parts[] = 'Advantage+ auditoriya';
        }

        return implode(' · ', array_filter($parts)) ?: '—';
    }

    /**
     * Short conclusions, green for what works and red for what to avoid.
     *
     * @return list<array{level: string, text: string}>
     */
    private function insights(Collection $ageGender, Collection $audiences, Collection $videos, ?float $cost, int $minCell = 10, int $minAud = 30): array
    {
        $out = [];
        $eligible = $ageGender->filter(fn ($r) => $r['leads'] >= $minCell && $r['cpl'] !== null)->sortBy('cpl')->values();
        $fmt = fn ($r) => "{$r['label']} (\${$r['cpl']}, {$r['leads']} ta)";

        if ($eligible->count() >= 2) {
            $out[] = ['level' => 'good', 'text' => 'Eng arzon auditoriya: '.$eligible->take(3)->map($fmt)->implode('; ')];
            $worst = $eligible->reverse()->filter(fn ($r) => $cost && $r['cpl'] >= $cost * 1.25)->take(3);
            if ($worst->isNotEmpty()) {
                $out[] = ['level' => 'bad', 'text' => 'Qimmat auditoriya, sarfni kamaytiring: '.$worst->map($fmt)->implode('; ')];
            }
        }

        $leadsTotal = max(1, $ageGender->sum('leads'));
        $top = $ageGender->sortByDesc('leads')->first();
        if ($top) {
            $out[] = ['level' => 'idle', 'text' => "Eng ko'p lid: {$top['label']} (".round($top['leads'] / $leadsTotal * 100).'% lidlar)'];
        }

        $bestAudience = $audiences->first(fn ($a) => $a['cost'] !== null && $a['results'] >= $minAud);
        if ($bestAudience) {
            $out[] = ['level' => 'good', 'text' => "Eng arzon adset: {$bestAudience['name']} (\${$bestAudience['cost']}, {$bestAudience['results']} ta)"];
        }

        if ($videos->isNotEmpty()) {
            $v = $videos->first();
            $out[] = ['level' => 'good', 'text' => "Eng arzon video: {$v['ad']->name} (\${$v['m']['cpl']}, {$v['m']['leads']} ta)"];
        }

        return $out;
    }
}
