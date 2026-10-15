<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\AdAlert;
use App\Models\AdBreakdown;
use App\Models\AdInsight;
use App\Models\FacebookAdAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AnalyticsQuery
{
    public function __construct(private AdAnalyzer $analyzer) {}

    /** @return Builder<AdInsight> */
    private function insights(Carbon $from, Carbon $to, ?int $accountId): Builder
    {
        return AdInsight::query()
            ->join('ads', 'ads.id', '=', 'ad_insights.ad_id')
            ->join('facebook_ad_accounts as fa', 'fa.id', '=', 'ads.facebook_ad_account_id')
            ->where('fa.is_tracked', true)
            ->when($accountId, fn ($q) => $q->where('fa.id', $accountId))
            ->whereBetween('ad_insights.date', [$from->toDateString(), $to->toDateString()]);
    }

    /** @return array<string, float|int|null> */
    public function totals(Carbon $from, Carbon $to, ?int $accountId): array
    {
        $row = $this->insights($from, $to, $accountId)
            ->selectRaw(AdMetrics::sumSelectTyped())
            ->first();

        return AdMetrics::derive($row?->toArray() ?? []);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function campaigns(Carbon $from, Carbon $to, ?int $accountId): Collection
    {
        return $this->insights($from, $to, $accountId)
            ->selectRaw('ads.campaign_id, ads.campaign_name, fa.name as account_name, '.AdMetrics::sumSelectTyped())
            ->groupBy('ads.campaign_id', 'ads.campaign_name', 'fa.name')
            ->get()
            ->map(fn ($r) => ['id' => $r->campaign_id, 'name' => $r->campaign_name ?: '(nomsiz)', 'account' => $r->account_name] + ['m' => AdMetrics::derive($r->toArray())])
            ->sortByDesc(fn ($r) => $r['m']['spend'])
            ->values();
    }

    /**
     * Per-account summary rows in a handful of grouped queries.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function accountCards(Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): Collection
    {
        $group = fn (Carbon $a, Carbon $b) => AdInsight::query()
            ->join('ads', 'ads.id', '=', 'ad_insights.ad_id')
            ->join('facebook_ad_accounts as fa', 'fa.id', '=', 'ads.facebook_ad_account_id')
            ->where('fa.is_tracked', true)
            ->whereBetween('ad_insights.date', [$a->toDateString(), $b->toDateString()])
            ->selectRaw('fa.id as account_id, '.AdMetrics::sumSelectTyped())
            ->groupBy('fa.id')
            ->get()
            ->keyBy('account_id');

        $current = $group($from, $to);
        $previous = $group($prevFrom, $prevTo);

        $spark = AdInsight::query()
            ->join('ads', 'ads.id', '=', 'ad_insights.ad_id')
            ->join('facebook_ad_accounts as fa', 'fa.id', '=', 'ads.facebook_ad_account_id')
            ->where('fa.is_tracked', true)
            ->whereBetween('ad_insights.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('fa.id as account_id, ad_insights.date as day, SUM(ad_insights.spend) spend, SUM(ad_insights.leads) leads')
            ->groupBy('fa.id', 'ad_insights.date')
            ->orderBy('ad_insights.date')
            ->get()
            ->groupBy('account_id');

        $latest = AdAlert::max('date');
        $alerts = $latest
            ? AdAlert::query()
                ->join('ads', 'ads.id', '=', 'ad_alerts.ad_id')
                ->whereDate('ad_alerts.date', Carbon::parse($latest)->toDateString())
                ->whereIn('ad_alerts.severity', ['critical', 'warning'])
                ->selectRaw('ads.facebook_ad_account_id as account_id, COUNT(*) n')
                ->groupBy('ads.facebook_ad_account_id')
                ->pluck('n', 'account_id')
            : collect();

        return FacebookAdAccount::where('is_tracked', true)->orderBy('name')->get()->map(fn ($account) => [
            'account' => $account,
            'm' => AdMetrics::derive($current->get($account->id)?->toArray() ?? []),
            'prev' => AdMetrics::derive($previous->get($account->id)?->toArray() ?? []),
            'spark' => collect($spark->get($account->id, []))->map(fn ($r) => [
                'day' => $r->day,
                'cpl' => $r->leads > 0 ? round($r->spend / $r->leads, 2) : null,
                'spend' => round($r->spend, 2),
            ])->values(),
            'alerts' => (int) $alerts->get($account->id, 0),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function daily(Carbon $from, Carbon $to, ?int $accountId, ?int $adId = null): Collection
    {
        return $this->insights($from, $to, $accountId)
            ->when($adId, fn ($q) => $q->where('ad_insights.ad_id', $adId))
            ->selectRaw('ad_insights.date as day, '.AdMetrics::sumSelectTyped())
            ->groupBy('ad_insights.date')
            ->orderBy('ad_insights.date')
            ->get()
            ->map(fn ($r) => ['date' => $r->day] + AdMetrics::derive($r->toArray()));
    }

    /** @return Collection<int, array<string, mixed>> */
    public function ads(Carbon $from, Carbon $to, ?int $accountId, ?array $adIds = null): Collection
    {
        $sums = $this->insights($from, $to, $accountId)
            ->when($adIds !== null, fn ($q) => $q->whereIn('ad_insights.ad_id', $adIds ?: [0]))
            ->selectRaw('ad_insights.ad_id, '.AdMetrics::sumSelect())
            ->groupBy('ad_insights.ad_id')
            ->get();

        $ads = Ad::with('account')->whereIn('id', $sums->pluck('ad_id'))->get()->keyBy('id');

        $rows = $sums->map(function ($row) use ($ads) {
            $ad = $ads[$row->ad_id];

            return ['ad' => $ad, 'm' => AdMetrics::derive($row->toArray() + ['rtype' => $ad->resultType()])];
        });

        // average cost per result, separately for every result type
        $avg = $rows->groupBy(fn ($r) => $r['m']['rtype'])->map(function ($group) {
            $results = $group->sum(fn ($r) => $r['m']['leads']);

            return $results > 0 ? $group->sum(fn ($r) => $r['m']['spend']) / $results : null;
        });

        return $rows->map(fn ($r) => $r + ['verdict' => $this->verdict($r['ad'], $r['m'], $avg[$r['m']['rtype']] ?? null)])
            ->sortByDesc(fn ($r) => $r['m']['spend'])->values();
    }

    /**
     * @param  array<string, float|int|null>  $m
     * @return array{level: string, label: string}
     */
    public function verdict(Ad $ad, array $m, ?float $avgCost): array
    {
        $cfg = config('analytics');
        $type = $m['rtype'] ?? 'leads';

        if ($type === 'other') {
            return ['level' => 'idle', 'label' => 'Qamrov/brend'];
        }

        $none = ['leads' => "Lid yo'q", 'messages' => "Xabar yo'q", 'traffic' => "Klik yo'q", 'landing' => "Landing view yo'q"][$type] ?? "Natija yo'q";

        if ($m['spend'] < $cfg['min_spend']) {
            return ['level' => 'idle', 'label' => "Ma'lumot kam"];
        }
        if ($m['leads'] === 0) {
            return $m['spend'] >= $cfg['no_lead_spend']
                ? ['level' => 'bad', 'label' => $none]
                : ['level' => 'idle', 'label' => "Ma'lumot kam"];
        }

        // traffic: judged only by the price of one click against a fixed norm
        if ($type === 'traffic') {
            return match (true) {
                $m['cpl'] <= $cfg['traffic_cpc_good'] => ['level' => 'good', 'label' => 'Norma'],
                $m['cpl'] <= $cfg['traffic_cpc_bad'] => ['level' => 'ok', 'label' => "O'rtacha"],
                default => ['level' => 'bad', 'label' => 'Qimmat'],
            };
        }

        // messages: fixed norm if the user set one, otherwise compare with the account's own average
        if ($type === 'messages' && $cfg['message_cost_good']) {
            return match (true) {
                $m['cpl'] <= $cfg['message_cost_good'] => ['level' => 'good', 'label' => 'Norma'],
                $m['cpl'] <= ($cfg['message_cost_bad'] ?? $cfg['message_cost_good'] * 1.5) => ['level' => 'ok', 'label' => "O'rtacha"],
                default => ['level' => 'bad', 'label' => 'Qimmat'],
            };
        }

        // the account's target price only applies to lead campaigns
        $target = ($type === 'leads' && $ad->account->target_cpl) ? (float) $ad->account->target_cpl : $avgCost;

        if ($target && $m['cpl'] <= $target * 0.9) {
            return ['level' => 'good', 'label' => 'Yaxshi'];
        }
        if ($target && $m['cpl'] > $target * 1.3) {
            return ['level' => 'bad', 'label' => 'Qimmat'];
        }

        return ['level' => 'ok', 'label' => "O'rtacha"];
    }

    /**
     * Sum a breakdown dimension across tracked ads.
     *
     * @param  list<string>  $dims
     * @return Collection<int, array<string, mixed>>
     */
    public function breakdown(string $kind, array $dims, ?int $accountId, ?int $adId = null): Collection
    {
        $cols = implode(', ', array_map(fn ($d) => "ad_breakdowns.$d", $dims));

        return AdBreakdown::query()
            ->join('ads', 'ads.id', '=', 'ad_breakdowns.ad_id')
            ->join('facebook_ad_accounts as fa', 'fa.id', '=', 'ads.facebook_ad_account_id')
            ->where('fa.is_tracked', true)
            ->where('ad_breakdowns.kind', $kind)
            ->when($accountId, fn ($q) => $q->where('fa.id', $accountId))
            ->when($adId, fn ($q) => $q->where('ad_breakdowns.ad_id', $adId))
            ->selectRaw("$cols, SUM(ad_breakdowns.spend) spend, SUM(ad_breakdowns.impressions) impressions, SUM(ad_breakdowns.link_clicks) link_clicks, SUM(ad_breakdowns.leads) leads")
            ->groupByRaw($cols)
            ->get()
            ->map(fn ($r) => $r->only($dims) + AdMetrics::derive($r->toArray()))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function weekdays(Carbon $from, Carbon $to, ?int $accountId): Collection
    {
        $names = [1 => 'Dushanba', 2 => 'Seshanba', 3 => 'Chorshanba', 4 => 'Payshanba', 5 => 'Juma', 6 => 'Shanba', 7 => 'Yakshanba'];

        return $this->daily($from, $to, $accountId)
            ->groupBy(fn ($d) => Carbon::parse($d['date'])->dayOfWeekIso)
            ->map(fn ($rows, $dow) => ['label' => $names[$dow], 'dow' => $dow] + AdMetrics::derive([
                'spend' => $rows->sum('spend'),
                'impressions' => $rows->sum('impressions'),
                'link_clicks' => $rows->sum('link_clicks'),
                'leads' => $rows->sum('leads'),
            ]))
            ->sortBy('dow')
            ->values();
    }

    public function analyzer(): AdAnalyzer
    {
        return $this->analyzer;
    }
}
