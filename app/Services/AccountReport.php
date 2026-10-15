<?php

namespace App\Services;

use App\Models\FacebookAdAccount;
use Illuminate\Support\Collection;

class AccountReport
{
    public function __construct(private AnalyticsQuery $q, private AdAnalyzer $analyzer, private LiveBreakdown $live) {}

    /**
     * Full structured report: headline metrics, statuses, reasons, recommendations.
     *
     * @return array<string, mixed>
     */
    public function build(FacebookAdAccount $account, ?DateRange $range = null, bool $withRecs = true): array
    {
        $range ??= DateRange::lastDays(7);
        $days = $range->days();
        $from = $range->from;
        $to = $range->to;
        $id = $account->id;

        $t = $this->q->totals($from, $to, $id);
        $p = $this->q->totals($range->prevFrom(), $range->prevTo(), $id);
        $ads = $this->q->ads($from, $to, $id);
        $window = ($wd = $range->windowDays()) ? $account->windows()->where('days', $wd)->first() : null;
        $freq = $window?->frequency ? round((float) $window->frequency, 2) : null;

        return [
            'account' => $account,
            'days' => $days,
            'range' => $range,
            'from' => $from,
            'to' => $to,
            't' => $t,
            'p' => $p,
            'freq' => $freq,
            'reach' => $window?->reach,
            'ads' => $ads,
            'statuses' => $this->statuses($account, $t, $p, $freq),
            'reasons' => $this->reasons($t, $p, $ads, $freq),
            'recs' => $withRecs ? $this->recommendations($account, $t, $ads, $range) : [],
        ];
    }

    /**
     * Metric-by-metric health: value, norm and traffic-light level.
     *
     * @param  array<string, float|int|null>  $t
     * @param  array<string, float|int|null>  $p
     * @return list<array{key: string, label: string, value: string, norm: string, level: string}>
     */
    public function statuses(FacebookAdAccount $account, array $t, array $p, ?float $freq): array
    {
        $c = config('analytics');
        $cur = $account->currency === 'USD' ? '$' : '';
        $rows = [];
        $add = function (string $key, string $label, ?string $value, string $norm, string $level) use (&$rows) {
            $rows[] = ['key' => $key, 'label' => $label, 'value' => $value ?? '—', 'norm' => $norm, 'level' => $value === null ? 'idle' : $level];
        };
        $vsPrev = function (?float $now, ?float $before, float $okPct = 0.05, float $badPct = 0.15, bool $lowerBetter = true): string {
            if ($now === null || $before === null || $before <= 0) {
                return 'idle';
            }
            $delta = $now / $before - 1;
            $delta = $lowerBetter ? $delta : -$delta;

            return $delta <= -$okPct ? 'good' : ($delta >= $badPct ? 'bad' : 'warn');
        };

        $rtype = $t['rtype'] ?? 'leads';
        $isLeads = $rtype === 'leads';
        $costLabel = $t['rcost'] ?? 'Lid narxi';
        if ($rtype === 'traffic') {
            $good = $c['traffic_cpc_good'];
            $bad = $c['traffic_cpc_bad'];
            $add('cpl', $costLabel, $t['cpl'] !== null ? $cur.number_format($t['cpl'], 3) : null, "norma ≤ {$cur}{$good}", $t['cpl'] === null ? 'idle' : ($t['cpl'] <= $good ? 'good' : ($t['cpl'] <= $bad ? 'warn' : 'bad')));

            return $rows;
        }

        $target = ($isLeads && $account->target_cpl) ? (float) $account->target_cpl : null;
        if ($target) {
            $lvl = $t['cpl'] === null ? 'idle' : ($t['cpl'] <= $target ? 'good' : ($t['cpl'] <= $target * 1.2 ? 'warn' : 'bad'));
            $add('cpl', $costLabel, $t['cpl'] !== null ? $cur.number_format($t['cpl'], 2) : null, "maqsad {$cur}{$target}", $lvl);
        } else {
            $add('cpl', $costLabel, $t['cpl'] !== null ? $cur.number_format($t['cpl'], 2) : null, $p['cpl'] ? "oldingi davr {$cur}{$p['cpl']}" : 'maqsad kiritilmagan', $vsPrev($t['cpl'], $p['cpl']));
        }
        $add('cpm', 'CPM', $t['cpm'] !== null ? $cur.$t['cpm'] : null, $p['cpm'] ? "oldingi davr {$cur}{$p['cpm']}" : '—', $vsPrev($t['cpm'], $p['cpm'], 0.05, 0.2));
        $add('ctr', 'CTR (link)', $t['ctr'] !== null ? $t['ctr'].'%' : null, "norma {$c['ctr_good']}%+", $t['ctr'] === null ? 'idle' : ($t['ctr'] >= $c['ctr_good'] ? 'good' : ($t['ctr'] >= $c['ctr_bad'] ? 'warn' : 'bad')));
        if ($isLeads) {
            $add('lpv', 'Landing view / klik', $t['lpv_rate'] !== null ? $t['lpv_rate'].'%' : null, "norma {$c['lpv_rate_good']}%+", $t['lpv_rate'] === null ? 'idle' : ($t['lpv_rate'] >= $c['lpv_rate_good'] ? 'good' : ($t['lpv_rate'] >= $c['lpv_rate_bad'] ? 'warn' : 'bad')));
            $add('lead_rate', 'Lid rate (lid / landing)', $t['lead_rate'] !== null ? $t['lead_rate'].'%' : null, "norma {$c['lead_rate_good']}%+", $t['lead_rate'] === null ? 'idle' : ($t['lead_rate'] >= $c['lead_rate_good'] ? 'good' : ($t['lead_rate'] >= $c['lead_rate_bad'] ? 'warn' : 'bad')));
        }
        $add('hook', 'Hook rate', $t['hook'] !== null ? $t['hook'].'%' : null, "norma {$c['hook_good']}%+", $t['hook'] === null ? 'idle' : ($t['hook'] >= $c['hook_good'] ? 'good' : ($t['hook'] >= $c['hook_bad'] ? 'warn' : 'bad')));
        $add('hold', 'Hold rate', $t['hold'] !== null ? $t['hold'].'%' : null, "norma {$c['hold_good']}%+", $t['hold'] === null ? 'idle' : ($t['hold'] >= $c['hold_good'] ? 'good' : ($t['hold'] >= $c['hold_bad'] ? 'warn' : 'bad')));
        $add('freq', 'Chastota', $freq !== null ? (string) $freq : null, "{$c['frequency_warn']} dan past bo'lsin", $freq === null ? 'idle' : ($freq <= $c['frequency_warn'] ? 'good' : ($freq <= $c['frequency_bad'] ? 'warn' : 'bad')));
        if ($isLeads) {
            $add('conv', 'Klik → lid', $t['conv'] !== null ? $t['conv'].'%' : null, $p['conv'] ? "oldingi davr {$p['conv']}%" : '—', $vsPrev($t['conv'], $p['conv'], 0.05, 0.15, false));
        }

        return $rows;
    }

    /**
     * Why did results change compared with the previous period.
     * CPL = CPM / (1000 * CTR * click-to-lead), so its change splits into three factors.
     *
     * @param  array<string, float|int|null>  $t
     * @param  array<string, float|int|null>  $p
     * @return list<array{level: string, text: string}>
     */
    public function reasons(array $t, array $p, Collection $ads, ?float $freq): array
    {
        $c = config('analytics');
        $out = [];
        $pct = fn (float $a, float $b) => ($b > 0) ? round(($a / $b - 1) * 100) : null;

        if ($t['cpl'] && $p['cpl']) {
            $change = $pct($t['cpl'], $p['cpl']);

            if (abs($change) >= 8) {
                $up = $change > 0;
                $out[] = ['level' => $up ? 'bad' : 'good', 'text' => sprintf('%s %d%% %s: %s → %s', $t['rcost'] ?? 'Lid narxi', abs($change), $up ? 'oshdi' : 'tushdi', $p['cpl'], $t['cpl'])];

                $factors = [];
                if ($t['cpm'] && $p['cpm']) {
                    $factors['cpm'] = [log($t['cpm'] / $p['cpm']), "CPM {$p['cpm']} → {$t['cpm']} (ko'rsatuv narxi ".($t['cpm'] > $p['cpm'] ? 'oshdi: raqobat yoki auditoriya toraydi' : 'tushdi').')'];
                }
                if ($t['ctr'] && $p['ctr']) {
                    $factors['ctr'] = [-log($t['ctr'] / $p['ctr']), "CTR {$p['ctr']}% → {$t['ctr']}% (".($t['ctr'] < $p['ctr'] ? 'kreativ kamroq qiziqtiryapti' : 'kreativ kuchliroq').')'];
                }
                if (in_array($t['rtype'] ?? 'leads', ['leads', 'messages'], true) && $t['conv'] && $p['conv']) {
                    $factors['conv'] = [-log($t['conv'] / $p['conv']), "Klikdan natijaga {$p['conv']}% → {$t['conv']}% (".($t['conv'] < $p['conv'] ? (($t['rtype'] ?? 'leads') === 'leads' ? 'lending/forma sifati tushdi' : 'xabar yozish kamaydi') : 'yaxshilandi').')'];
                }

                $total = array_sum(array_map(fn ($f) => abs($f[0]), $factors)) ?: 1;
                uasort($factors, fn ($a, $b) => abs($b[0]) <=> abs($a[0]));

                foreach ($factors as $f) {
                    if (abs($f[0]) / $total < 0.15) {
                        continue;
                    }
                    $worse = $f[0] > 0;
                    $out[] = ['level' => $worse ? 'bad' : 'good', 'text' => round(abs($f[0]) / $total * 100).'% ta\'sir: '.$f[1]];
                }
            } else {
                $out[] = ['level' => 'good', 'text' => ($t['rcost'] ?? 'Lid narxi')." barqaror ({$change}%): {$p['cpl']} → {$t['cpl']}"];
            }
        }

        if ($t['spend'] && $p['spend']) {
            $s = $pct($t['spend'], $p['spend']);
            $l = $pct($t['leads'] ?: 0.0001, max($p['leads'], 1));
            if (abs($s) >= 10) {
                $out[] = ['level' => 'idle', 'text' => sprintf('Sarf %s%d%%, lidlar %s%d%% ga o\'zgardi', $s >= 0 ? '+' : '−', abs($s), $l >= 0 ? '+' : '−', abs($l))];
            }
        }

        if ($freq !== null && $freq >= $c['frequency_warn']) {
            $out[] = ['level' => 'bad', 'text' => "Chastota {$freq}: auditoriya reklamani ko'p ko'rib charchayapti."];
        }
        if ($t['hook'] !== null && $p['hook'] && $t['hook'] < $p['hook'] * 0.85) {
            $out[] = ['level' => 'bad', 'text' => "Hook rate tushdi: {$p['hook']}% → {$t['hook']}% (videolarning boshlanishi kuchsizlandi)."];
        }
        if ($t['hold'] !== null && $p['hold'] && $t['hold'] < $p['hold'] * 0.85) {
            $out[] = ['level' => 'bad', 'text' => "Hold rate tushdi: {$p['hold']}% → {$t['hold']}%."];
        }

        $wasted = $ads->filter(fn ($r) => $r['m']['leads'] === 0 && $r['m']['spend'] >= $c['no_lead_spend']);
        if ($wasted->isNotEmpty()) {
            $out[] = ['level' => 'bad', 'text' => sprintf('%d ta reklama natija bermay %s sarfladi (umumiy sarfning %d%%).', $wasted->count(), number_format($wasted->sum(fn ($r) => $r['m']['spend']), 2), $t['spend'] ? round($wasted->sum(fn ($r) => $r['m']['spend']) / $t['spend'] * 100) : 0)];
        }

        $good = $ads->filter(fn ($r) => $r['verdict']['level'] === 'good');
        $bad = $ads->filter(fn ($r) => $r['verdict']['level'] === 'bad' && $r['m']['leads'] > 0);
        if ($good->isNotEmpty()) {
            $out[] = ['level' => 'good', 'text' => sprintf('%d ta reklama o\'rtachadan arzon natija beryapti (%d%% sarf).', $good->count(), $t['spend'] ? round($good->sum(fn ($r) => $r['m']['spend']) / $t['spend'] * 100) : 0)];
        }
        if ($bad->isNotEmpty()) {
            $out[] = ['level' => 'bad', 'text' => sprintf('%d ta reklamada natija narxi me\'yordan qimmat (%d%% sarf).', $bad->count(), $t['spend'] ? round($bad->sum(fn ($r) => $r['m']['spend']) / $t['spend'] * 100) : 0)];
        }

        return $out ?: [['level' => 'idle', 'text' => "Solishtirish uchun oldingi davr ma'lumoti yetarli emas."]];
    }

    /**
     * Concrete actions: red = fix or cut, green = scale or repeat.
     *
     * @param  array<string, float|int|null>  $t
     * @return list<array{level: string, title: string, detail: string}>
     */
    public function recommendations(FacebookAdAccount $account, array $t, Collection $ads, DateRange $range): array
    {
        $c = config('analytics');
        $out = [];
        $names = fn (Collection $rows, int $n = 3) => $rows->take($n)->map(fn ($r) => $r['ad']->name)->implode(', ');
        $totalSpend = max(0.01, (float) $t['spend']);

        $wasters = $ads->filter(fn ($r) => $r['m']['leads'] === 0 && $r['m']['spend'] >= $c['no_lead_spend'])->sortByDesc(fn ($r) => $r['m']['spend']);
        if ($wasters->isNotEmpty()) {
            $out[] = ['level' => 'bad', 'title' => "Natija bermayotgan {$wasters->count()} ta reklamani o'chiring", 'detail' => 'Sarf: '.number_format($wasters->sum(fn ($r) => $r['m']['spend']), 2).'. Eng ko\'p sarflaganlar: '.$names($wasters).'. Byudjetni ishlayotganlarga o\'tkazing.'];
        }

        $expensive = $ads->filter(fn ($r) => $r['verdict']['level'] === 'bad' && $r['m']['leads'] > 0)->sortByDesc(fn ($r) => $r['m']['spend']);
        if ($expensive->isNotEmpty()) {
            $out[] = ['level' => 'bad', 'title' => "Qimmat {$expensive->count()} ta reklamani arzonlashtiring yoki to'xtating", 'detail' => 'Lid narxi yuqori: '.$names($expensive).'. Kreativni yangilang, auditoriyani toraytiring; 3–4 kunda yaxshilanmasa o\'chiring.'];
        }

        $freqAds = $ads->filter(fn ($r) => $r['ad']->resultType() !== 'traffic' && (float) $r['ad']->frequency_7d >= $c['frequency_bad'] && $r['m']['spend'] >= $c['min_spend']);
        if ($freqAds->isNotEmpty()) {
            $out[] = ['level' => 'bad', 'title' => "{$freqAds->count()} ta reklamada chastota {$c['frequency_bad']} dan yuqori", 'detail' => $names($freqAds).'. Yangi kreativ qo\'shing yoki auditoriyani kengaytiring; aks holda lid narxi ko\'tarilaveradi.'];
        }

        $videos = $ads->filter(fn ($r) => $r['ad']->is_video && $r['ad']->resultType() !== 'traffic' && $r['m']['spend'] >= $c['min_spend']);
        $lowHook = $videos->filter(fn ($r) => $r['m']['hook'] !== null && $r['m']['hook'] < $c['hook_bad']);
        if ($lowHook->isNotEmpty()) {
            $out[] = ['level' => 'bad', 'title' => "{$lowHook->count()} ta videoda hook past (<{$c['hook_bad']}%)", 'detail' => $names($lowHook).'. Birinchi 3 soniyani almashtiring: savol, natija yoki kutilmagan kadr bilan boshlang.'];
        }
        $lowHold = $videos->filter(fn ($r) => $r['m']['hold'] !== null && $r['m']['hold'] < $c['hold_bad']);
        if ($lowHold->isNotEmpty()) {
            $out[] = ['level' => 'bad', 'title' => "{$lowHold->count()} ta videoda hold past (<{$c['hold_bad']}%)", 'detail' => $names($lowHold).'. Videoni qisqartiring, taklifni erta ayting, sekin boshlanishni olib tashlang.'];
        }

        if (($t['rtype'] ?? 'leads') === 'leads' && $t['lpv_rate'] !== null && $t['link_clicks'] > 100 && $t['lpv_rate'] < $c['lpv_rate_bad']) {
            $out[] = ['level' => 'bad', 'title' => 'Lending sekin yoki klik sifatsiz', 'detail' => 'Kliklarning '.round(100 - $t['lpv_rate'])."% i lendingga yetib bormayapti. Mobil tezlikni tekshiring, rasmlarni siqing, pixel to'g'ri ishlashini ko'ring."];
        }
        if (($t['rtype'] ?? 'leads') === 'leads' && $t['lead_rate'] !== null && $t['landing_views'] > 100 && $t['lead_rate'] < $c['lead_rate_bad']) {
            $out[] = ['level' => 'bad', 'title' => 'Lid rate past — lending/formani yaxshilang', 'detail' => "Lendingga kirganlarning faqat {$t['lead_rate']}% i lid qoldiryapti. Birinchi ekranda taklifni aniq qiling, formani qisqartiring (ism + telefon)."];
        }

        foreach ([
            ['placement', ['platform', 'position'], fn ($r) => ucfirst($r['platform'] ?? '?').' · '.str_replace('_', ' ', $r['position'] ?? '?'), 'joylashuv'],
            ['region', ['region'], fn ($r) => $r['region'] ?? '?', 'hudud'],
            ['age_gender', ['age', 'gender'], fn ($r) => (['male' => 'Erkak', 'female' => 'Ayol'][$r['gender']] ?? $r['gender']).', '.$r['age'], 'auditoriya'],
        ] as [$kind, $dims, $label, $word]) {
            $rows = $this->safeLive($account, $kind, $dims, $range)->filter(fn ($r) => strtolower((string) ($r['age'] ?? '')) !== 'unknown' && strtolower((string) ($r['gender'] ?? '')) !== 'unknown');
            $out = array_merge($out, $this->breakdownRecs($rows, $label, $word));
        }

        $hours = $this->safeLive($account, 'hour', ['hour'], $range);
        $hourRecs = $this->hourRecs($hours);
        $out = array_merge($out, $hourRecs);

        $winners = $ads->filter(fn ($r) => $r['verdict']['level'] === 'good' && $r['m']['leads'] >= 5)->sortBy(fn ($r) => $r['m']['cpl']);
        if ($winners->isNotEmpty()) {
            $out[] = ['level' => 'good', 'title' => "{$winners->count()} ta yaxshi reklamaga byudjet qo'shing", 'detail' => $names($winners).'. Kuniga 15–20% dan oshiring (keskin oshirsangiz o\'rganish buziladi), shunga o\'xshash yangi kreativlar yarating.'];
        }
        $goodVideos = $videos->filter(fn ($r) => $r['m']['hook'] !== null && $r['m']['hook'] >= $c['hook_good'] && $r['m']['hold'] !== null && $r['m']['hold'] >= $c['hold_good']);
        if ($goodVideos->isNotEmpty()) {
            $out[] = ['level' => 'good', 'title' => 'Kuchli videolar: hook ham, hold ham yaxshi', 'detail' => $names($goodVideos).'. Ularning uslubi va boshlanishini yangi videolarda takrorlang.'];
        }

        usort($out, fn ($a, $b) => ($a['level'] === 'bad' ? 0 : 1) <=> ($b['level'] === 'bad' ? 0 : 1));

        return $out ?: [['level' => 'good', 'title' => 'Hozircha jiddiy muammo yo\'q', 'detail' => 'Ko\'rsatkichlarni kuzatishda davom eting.']];
    }

    /**
     * @param  list<string>  $dims
     * @return Collection<int, array<string, mixed>>
     */
    private function safeLive(FacebookAdAccount $account, string $kind, array $dims, DateRange $range): Collection
    {
        try {
            return $this->live->get($account, $kind, $dims, $range->from, $range->to);
        } catch (\Throwable) {
            return $this->q->breakdown($kind, $dims, $account->id);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{level: string, title: string, detail: string}>
     */
    private function breakdownRecs(Collection $rows, callable $label, string $word): array
    {
        $rows = $rows->map(fn ($r) => $r + ['name' => $label($r)]);
        $spend = max(0.01, (float) $rows->sum('spend'));
        $eligible = $rows->filter(fn ($r) => $r['cpl'] !== null && $r['leads'] >= 5)->sortBy('cpl')->values();

        if ($eligible->count() < 3) {
            return [];
        }

        $median = $eligible->get(intdiv($eligible->count(), 2))['cpl'];
        $out = [];

        $worst = $eligible->filter(fn ($r) => $r['cpl'] >= $median * 1.3 && $r['spend'] / $spend >= 0.05)->sortByDesc('cpl')->take(3);
        if ($worst->isNotEmpty()) {
            $out[] = ['level' => 'bad', 'title' => "Qimmat {$word}: sarfni kamaytiring", 'detail' => $worst->map(fn ($r) => "{$r['name']} (CPL {$r['cpl']}, {$r['leads']} lid)")->implode('; ')."; o'rtacha CPL {$median}."];
        }

        $best = $eligible->filter(fn ($r) => $r['cpl'] <= $median * 0.85)->take(3);
        if ($best->isNotEmpty()) {
            $out[] = ['level' => 'good', 'title' => "Arzon {$word}: kuchaytiring", 'detail' => $best->map(fn ($r) => "{$r['name']} (CPL {$r['cpl']}, {$r['leads']} lid)")->implode('; ')."; o'rtacha CPL {$median}."];
        }

        return $out;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $hours
     * @return list<array{level: string, title: string, detail: string}>
     */
    private function hourRecs(Collection $hours): array
    {
        $eligible = $hours->filter(fn ($r) => $r['cpl'] !== null && $r['leads'] >= 3);
        if ($eligible->count() < 6) {
            return [];
        }

        $median = $eligible->sortBy('cpl')->values()->get(intdiv($eligible->count(), 2))['cpl'];
        $fmt = fn (Collection $rows) => $rows->sortBy('hour')->map(fn ($r) => sprintf('%02d:00 (%s)', $r['hour'], $r['cpl']))->implode(', ');
        $best = $eligible->filter(fn ($r) => $r['cpl'] <= $median * 0.8);
        $worst = $eligible->filter(fn ($r) => $r['cpl'] >= $median * 1.35);
        $out = [];

        if ($worst->isNotEmpty()) {
            $out[] = ['level' => 'bad', 'title' => 'Qimmat soatlar', 'detail' => $fmt($worst)." — bu soatlarda lid qimmat (o'rtacha {$median}). Jadval (ad schedule) orqali o'chirishni ko'rib chiqing."];
        }
        if ($best->isNotEmpty()) {
            $out[] = ['level' => 'good', 'title' => 'Arzon soatlar', 'detail' => $fmt($best)." — bu soatlarda lid arzon (o'rtacha {$median}). Byudjetni shu vaqtlarga jamlang."];
        }

        return $out;
    }
}
