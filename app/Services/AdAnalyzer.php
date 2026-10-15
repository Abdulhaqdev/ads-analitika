<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\AdAlert;
use App\Models\AdInsight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AdAnalyzer
{
    /**
     * Analyse every ad that spent money recently and store today's alerts.
     *
     * @return int number of alerts written
     */
    public function run(?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $recentDays = config('analytics.recent_days');
        $baseDays = config('analytics.baseline_days');

        $recentEnd = $today->copy()->subDay();
        $recentStart = $recentEnd->copy()->subDays($recentDays - 1);
        $baseEnd = $recentStart->copy()->subDay();
        $baseStart = $baseEnd->copy()->subDays($baseDays - 1);

        $count = 0;

        Ad::with('account')
            ->whereHas('account', fn ($q) => $q->where('is_tracked', true))
            ->whereHas('insights', fn ($q) => $q->where('date', '>=', $recentStart))
            ->each(function (Ad $ad) use ($today, $recentStart, $recentEnd, $baseStart, $baseEnd, &$count) {
                $recent = $this->window($ad, $recentStart, $recentEnd);
                $base = $this->window($ad, $baseStart, $baseEnd);

                foreach ($this->evaluate($ad, $recent, $base) as $alert) {
                    $existing = AdAlert::where('ad_id', $ad->id)->whereDate('date', $today->toDateString())->where('type', $alert['type'])->first();
                    $payload = $alert + ['metrics' => ['recent' => $recent, 'base' => $base]];
                    $existing
                        ? $existing->update($payload)
                        : AdAlert::create($payload + ['ad_id' => $ad->id, 'date' => $today->toDateString()]);
                    $count++;
                }
            });

        return $count;
    }

    /** @return array<string, float|int|null> */
    public function window(Ad $ad, Carbon $from, Carbon $to): array
    {
        $row = AdInsight::where('ad_id', $ad->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw(AdMetrics::sumSelect())
            ->first();

        return AdMetrics::derive(($row?->toArray() ?? []) + ['rtype' => $ad->resultType()]);
    }

    /**
     * @param  array<string, float|int|null>  $recent
     * @param  array<string, float|int|null>  $base
     * @return list<array{type: string, severity: string, title: string, reasons: list<string>, recommendation: string}>
     */
    public function evaluate(Ad $ad, array $recent, array $base): array
    {
        $cfg = config('analytics');
        $cur = $ad->account->currency ?? 'USD';
        $type = $ad->resultType();
        $target = ($type === 'leads' && $ad->account->target_cpl) ? (float) $ad->account->target_cpl : null;
        $costName = ResultType::COST[$type] ?? 'Narx';
        $noun = mb_strtolower(ResultType::SHORT[$type] ?? 'natija');
        $alerts = [];

        if ($type === 'other' || $recent['spend'] < $cfg['min_spend']) {
            return [];
        }

        if ($type === 'traffic') {
            return $this->evaluateNorm($ad, $recent, $base, $cur, $cfg['traffic_cpc_good'], $cfg['traffic_cpc_bad'], 20);
        }

        if ($type === 'messages' && $cfg['message_cost_good']) {
            return $this->evaluateNorm($ad, $recent, $base, $cur, $cfg['message_cost_good'], $cfg['message_cost_bad'] ?? $cfg['message_cost_good'] * 1.5, 3);
        }

        if ($recent['leads'] === 0 && $recent['spend'] >= max($cfg['no_lead_spend'], ($target ?? 0) * 2)) {
            $alerts[] = [
                'type' => 'no_leads',
                'severity' => 'critical',
                'title' => ucfirst($noun)." yo'q: {$recent['spend']} {$cur} sarflandi",
                'reasons' => $this->reasons($recent, $base, $ad),
                'recommendation' => $type === 'leads'
                    ? "Reklamani to'xtating yoki yangi kreativ/auditoriya bilan almashtiring. Avval lending va forma ishlayotganini tekshiring (klik bor-u lid yo'q bo'lsa muammo lendingda)."
                    : "Reklamani to'xtating yoki yangi kreativ/auditoriya bilan almashtiring.",
            ];
        }

        if ($recent['cpl'] && $base['cpl'] && $base['leads'] >= 3) {
            $ratio = $recent['cpl'] / $base['cpl'];

            if ($ratio >= $cfg['cpl_spike_warn']) {
                $reasons = $this->reasons($recent, $base, $ad);
                $alerts[] = [
                    'type' => 'cpl_spike',
                    'severity' => $ratio >= $cfg['cpl_spike_critical'] ? 'critical' : 'warning',
                    'title' => sprintf('%s %d%% oshdi: %s → %s %s', $costName, ($ratio - 1) * 100, $base['cpl'], $recent['cpl'], $cur),
                    'reasons' => $reasons ?: ["Aniq bitta sabab topilmadi: ko'rsatkichlar qisman o'zgargan, auksion narxlari tebranishi mumkin."],
                    'recommendation' => $this->recommend($recent, $base, $ad),
                ];
            }

            if ($ratio <= 0.8 && $recent['leads'] >= 3) {
                $alerts[] = [
                    'type' => 'winner',
                    'severity' => 'good',
                    'title' => sprintf('Yaxshilanmoqda: %s %d%% tushdi (%s → %s %s)', mb_strtolower($costName), (1 - $ratio) * 100, $base['cpl'], $recent['cpl'], $cur),
                    'reasons' => [],
                    'recommendation' => "Byudjetni sekin (har kuni 15–20%) oshiring, keskin ko'paytirsangiz o'rganish jarayoni buziladi.",
                ];
            }
        }

        if ($target && $recent['cpl'] && $recent['cpl'] > $target * 1.2 && $recent['leads'] >= 2) {
            $alerts[] = [
                'type' => 'over_target',
                'severity' => 'warning',
                'title' => sprintf('%s maqsaddan yuqori: %s %s (maqsad %s)', $costName, $recent['cpl'], $cur, $target),
                'reasons' => $this->reasons($recent, $base, $ad),
                'recommendation' => $this->recommend($recent, $base, $ad),
            ];
        }

        $freq = (float) $ad->frequency_7d;
        if ($freq >= $cfg['frequency_warn'] && $recent['spend'] >= $cfg['min_spend']) {
            $alerts[] = [
                'type' => 'frequency',
                'severity' => $freq >= $cfg['frequency_bad'] ? 'critical' : 'warning',
                'title' => "Chastota yuqori: {$freq} (7 kun)",
                'reasons' => ["Bir odamga reklama o'rtacha {$freq} marta ko'rsatilgan, auditoriya charchaydi."],
                'recommendation' => "Auditoriyani kengaytiring yoki yangi kreativ qo'shing. Chastota 3.5 dan oshsa lid narxi odatda tez ko'tariladi.",
            ];
        }

        return $alerts;
    }

    /**
     * Traffic ads are judged only by the price of one click against a fixed norm.
     *
     * @param  array<string, float|int|null>  $recent
     * @param  array<string, float|int|null>  $base
     * @return list<array{type: string, severity: string, title: string, reasons: list<string>, recommendation: string}>
     */
    private function evaluateNorm(Ad $ad, array $recent, array $base, string $cur, float $good, float $bad, int $minResults): array
    {
        $cfg = config('analytics');
        $costName = ResultType::COST[$ad->resultType()] ?? 'Narx';
        $noun = mb_strtolower(ResultType::SHORT[$ad->resultType()] ?? 'natija');
        $cpc = $recent['cpl'];

        if ($recent['leads'] === 0 && $recent['spend'] >= $cfg['no_lead_spend']) {
            return [['type' => 'no_leads', 'severity' => 'critical', 'title' => ucfirst($noun)." yo'q: {$recent['spend']} {$cur} sarflandi", 'reasons' => [], 'recommendation' => "Reklamani to'xtating yoki almashtiring."]];
        }

        if ($cpc === null || $recent['leads'] < $minResults) {
            return [];
        }

        if ($cpc > $bad) {
            $why = [];
            if ($base['cpl'] && $cpc > $base['cpl'] * 1.25) {
                $why[] = "{$costName} oshgan: {$base['cpl']} → {$cpc}";
            }
            if ($base['ctr'] && $recent['ctr'] !== null && $recent['ctr'] < $base['ctr'] * 0.8) {
                $why[] = "CTR tushgan: {$base['ctr']}% → {$recent['ctr']}%";
            }
            if ($base['cpm'] && $recent['cpm'] !== null && $recent['cpm'] > $base['cpm'] * 1.25) {
                $why[] = "CPM oshgan: {$base['cpm']} → {$recent['cpm']}";
            }

            return [[
                'type' => 'cpl_spike',
                'severity' => $cpc > $bad * 1.5 ? 'critical' : 'warning',
                'title' => "{$costName} yuqori: {$cpc} {$cur} (norma ≤ {$good})",
                'reasons' => $why,
                'recommendation' => 'Yangi rasm/sarlavha yoki matn sinang, auditoriyani o\'zgartiring; yaxshilanmasa o\'chiring.',
            ]];
        }

        if ($cpc <= $good && $base['cpl'] && $cpc <= $base['cpl'] * 0.8 && $recent['leads'] >= $minResults * 2) {
            return [['type' => 'winner', 'severity' => 'good', 'title' => "{$costName} tushdi: {$base['cpl']} → {$cpc} {$cur}", 'reasons' => [], 'recommendation' => 'Byudjetni sekin oshiring.']];
        }

        return [];
    }

    /**
     * @param  array<string, float|int|null>  $r
     * @param  array<string, float|int|null>  $b
     * @return list<string>
     */
    public function reasons(array $r, array $b, Ad $ad): array
    {
        $cfg = config('analytics');
        $out = [];

        $freq = (float) $ad->frequency_7d;
        if ($freq >= $cfg['frequency_warn']) {
            $out[] = "Chastota yuqori ({$freq}): auditoriya bir xil reklamani ko'p ko'ryapti.";
        }

        if ($this->drop($r['ctr'], $b['ctr'], 0.2)) {
            $out[] = "CTR tushdi: {$b['ctr']}% → {$r['ctr']}%. Kreativ odamlarni kam qiziqtiryapti.";
        } elseif ($r['ctr'] !== null && $r['ctr'] < $cfg['ctr_bad']) {
            $out[] = "CTR past ({$r['ctr']}%): reklama e'tiborni tortmayapti.";
        }

        if ($this->rise($r['cpm'], $b['cpm'], 0.25)) {
            $out[] = "CPM oshdi: {$b['cpm']} → {$r['cpm']}. Ko'rsatuv narxi ko'tarilgan (raqobat yoki auditoriya kichrayishi).";
        }

        if ($ad->is_video) {
            if ($r['hook'] !== null && ($r['hook'] < $cfg['hook_bad'] || $this->drop($r['hook'], $b['hook'], 0.2))) {
                $was = $b['hook'] !== null ? " (avval {$b['hook']}%)" : '';
                $out[] = "Hook rate past: {$r['hook']}%{$was}. Videoning birinchi 3 soniyasi kuchsiz.";
            }
            if ($r['hold'] !== null && ($r['hold'] < $cfg['hold_bad'] || $this->drop($r['hold'], $b['hold'], 0.2))) {
                $was = $b['hold'] !== null ? " (avval {$b['hold']}%)" : '';
                $out[] = "Hold rate past: {$r['hold']}%{$was}. Video boshidan keyin qiziqish yo'qolmoqda.";
            }
        }

        if ($ad->resultType() === 'leads' && $this->drop($r['conv'], $b['conv'], 0.25)) {
            $out[] = "Kliklardan lidga o'tish tushdi: {$b['conv']}% → {$r['conv']}%. Lending yoki forma muammosi bo'lishi mumkin.";
        }

        return $out;
    }

    /**
     * @param  array<string, float|int|null>  $r
     * @param  array<string, float|int|null>  $b
     */
    public function recommend(array $r, array $b, Ad $ad): string
    {
        $cfg = config('analytics');
        $tips = [];

        if ((float) $ad->frequency_7d >= $cfg['frequency_warn']) {
            $tips[] = "chastota yuqori, shuning uchun yangi kreativ qo'shing yoki auditoriyani kengaytiring";
        }
        if ($ad->is_video && $r['hook'] !== null && $r['hook'] < $cfg['hook_bad']) {
            $tips[] = "videoning birinchi 3 soniyasini o'zgartiring (kuchli savol, natija yoki kutilmagan kadr bilan boshlang)";
        }
        if ($ad->is_video && $r['hold'] !== null && $r['hold'] < $cfg['hold_bad']) {
            $tips[] = 'videoni qisqartiring va asosiy taklifni erta ayting';
        }
        if ($ad->resultType() === 'leads' && $this->drop($r['conv'], $b['conv'], 0.25)) {
            $tips[] = 'lending tezligi va formani tekshiring (telefon raqam maydoni, yuklanish vaqti)';
        }
        if ($this->rise($r['cpm'], $b['cpm'], 0.25) && ! $tips) {
            $tips[] = "auditoriya yoki joylashuvni (placement) o'zgartirib ko'ring, bir necha kun kuting";
        }

        return $tips
            ? ucfirst(implode('; ', $tips)).'.'
            : "Reklamani bir necha kun kuzating; yaxshilanmasa yangi kreativ sinang va eng qimmat auditoriyani o'chiring.";
    }

    /**
     * Account-level health checks that tell what to fix first.
     *
     * @param  array<string, float|int|null>  $t  account totals
     * @param  Collection<int, array<string, mixed>>  $ads
     * @return list<array{level: string, title: string, detail: string}>
     */
    public function accountChecks(array $t, $ads, ?float $accountFrequency): array
    {
        $cfg = config('analytics');
        $out = [];

        if ($accountFrequency !== null && $accountFrequency >= $cfg['frequency_warn']) {
            $out[] = ['level' => $accountFrequency >= $cfg['frequency_bad'] ? 'bad' : 'warn', 'title' => "Akkaunt chastotasi yuqori: {$accountFrequency}", 'detail' => "Auditoriya bir xil reklamalarni ko'p ko'ryapti. Yangi kreativlar qo'shing yoki auditoriyani kengaytiring."];
        }

        if ($t['lpv_rate'] !== null && $t['link_clicks'] > 100 && $t['lpv_rate'] < $cfg['lpv_rate_bad']) {
            $lost = round(100 - $t['lpv_rate']);
            $out[] = ['level' => 'bad', 'title' => "Kliklarning {$lost}% i lendingga yetib bormayapti", 'detail' => "Landing view/klik = {$t['lpv_rate']}%. Lending sekin ochilmoqda yoki noto'g'ri bosishlar ko'p. Lending tezligini (mobil) tekshiring."];
        }

        if ($t['lead_rate'] !== null && $t['landing_views'] > 100 && $t['lead_rate'] < $cfg['lead_rate_bad']) {
            $out[] = ['level' => 'warn', 'title' => "Lid rate past: {$t['lead_rate']}%", 'detail' => "Lendingga kirganlarning {$t['lead_rate']}% i lid qoldiryapti. Forma, taklif va birinchi ekranni yaxshilang."];
        }

        $videos = $ads->filter(fn ($r) => $r['ad']->is_video && $r['m']['spend'] >= $cfg['min_spend']);
        $badHook = $videos->filter(fn ($r) => $r['m']['hook'] !== null && $r['m']['hook'] < $cfg['hook_bad']);
        if ($badHook->isNotEmpty()) {
            $out[] = ['level' => 'warn', 'title' => "{$badHook->count()} ta videoda hook rate past (<{$cfg['hook_bad']}%)", 'detail' => 'Birinchi 3 soniya kuchsiz: '.$badHook->take(3)->map(fn ($r) => $r['ad']->name)->implode(', ').'. Ularning boshlanishini almashtiring.'];
        }
        $badHold = $videos->filter(fn ($r) => $r['m']['hold'] !== null && $r['m']['hold'] < $cfg['hold_bad']);
        if ($badHold->isNotEmpty()) {
            $out[] = ['level' => 'warn', 'title' => "{$badHold->count()} ta videoda hold rate past (<{$cfg['hold_bad']}%)", 'detail' => 'Tomoshabin boshida qiziqib, keyin ketyapti: '.$badHold->take(3)->map(fn ($r) => $r['ad']->name)->implode(', ').'. Videoni qisqartiring, taklifni erta ayting.'];
        }

        $wasters = $ads->filter(fn ($r) => $r['m']['leads'] === 0 && $r['m']['spend'] >= $cfg['no_lead_spend']);
        if ($wasters->isNotEmpty()) {
            $sum = round($wasters->sum(fn ($r) => $r['m']['spend']), 2);
            $out[] = ['level' => 'bad', 'title' => "{$wasters->count()} ta reklama lid bermay {$sum} sarflagan", 'detail' => "Ularni o'chirib, byudjetni ishlayotgan reklamalarga o'tkazing: ".$wasters->take(3)->map(fn ($r) => $r['ad']->name)->implode(', ').'.'];
        }

        $winners = $ads->filter(fn ($r) => $r['verdict']['level'] === 'good');
        if ($winners->isNotEmpty()) {
            $out[] = ['level' => 'good', 'title' => "{$winners->count()} ta reklama yaxshi ishlayapti", 'detail' => "Byudjetni sekin oshiring (kuniga 15–20%) va shu kreativlarga o'xshash yangilarini yarating: ".$winners->take(3)->map(fn ($r) => $r['ad']->name)->implode(', ').'.'];
        }

        return $out;
    }

    private function drop(?float $now, ?float $before, float $pct): bool
    {
        return $now !== null && $before !== null && $before > 0 && $now < $before * (1 - $pct);
    }

    private function rise(?float $now, ?float $before, float $pct): bool
    {
        return $now !== null && $before !== null && $before > 0 && $now > $before * (1 + $pct);
    }
}
