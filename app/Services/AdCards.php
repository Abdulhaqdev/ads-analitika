<?php

namespace App\Services;

use App\Models\AdAlert;
use App\Models\AdInsight;
use Illuminate\Support\Collection;

/**
 * Turns stored alerts into short, visual per-ad cards:
 * picture, price before/after, tiny trend, one-line cause, one-line action.
 */
class AdCards
{
    private const PRIORITY = ['no_leads' => 0, 'cpl_spike' => 1, 'over_target' => 2, 'frequency' => 3, 'winner' => 4];

    /**
     * @param  Collection<int, AdAlert>  $alerts
     * @return Collection<int, array<string, mixed>>
     */
    public function fromAlerts(Collection $alerts): Collection
    {
        if ($alerts->isEmpty()) {
            return collect();
        }

        $series = AdInsight::whereIn('ad_id', $alerts->pluck('ad_id')->unique())
            ->where('date', '>=', today()->subDays(8)->toDateString())
            ->orderBy('date')->get()->groupBy('ad_id');

        return $alerts->groupBy('ad_id')->map(function (Collection $group) use ($series) {
            $alert = $group->sortBy(fn ($a) => self::PRIORITY[$a->type] ?? 9)->first();
            $ad = $alert->ad;
            $m = $alert->metrics ?? [];
            $r = $m['recent'] ?? [];
            $b = $m['base'] ?? [];
            $now = $r['cpl'] ?? null;
            $before = $b['cpl'] ?? null;
            $change = ($now && $before) ? round(($now / $before - 1) * 100) : null;

            [$kind, $cause, $action] = $this->explain($alert->type, $ad, $r, $b, $change);
            $rtype = $ad->resultType();

            return [
                'ad' => $ad,
                'rlabel' => ResultType::SHORT[$rtype] ?? 'Lid',
                'kind' => $kind,
                'now' => $now,
                'before' => $before,
                'change' => $change,
                'spend' => $r['spend'] ?? null,
                'hook' => $r['hook'] ?? null,
                'hold' => $r['hold'] ?? null,
                'spark' => collect($series[$ad->id] ?? [])->map(fn ($i) => $i->results > 0 ? round($i->spend / $i->results, 2) : null)->values()->all(),
                'cause' => $cause,
                'action' => $action,
                'severity' => ['no_leads' => 0, 'cpl_spike' => $change !== null && $change >= 100 ? 0 : 1, 'over_target' => 2, 'frequency' => 2, 'winner' => 5][$alert->type] ?? 3,
            ];
        })->sortBy([['severity', 'asc'], ['spend', 'desc']])->values();
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $b
     * @return array{0: string, 1: string, 2: string}
     */
    private function explain(string $type, $ad, array $r, array $b, ?int $change): array
    {
        $c = config('analytics');

        if ($type === 'winner') {
            return ['good', "Lid narxi tushdi: {$b['cpl']} → {$r['cpl']}", 'Byudjetni kuniga 15–20% oshiring'];
        }

        if ($type === 'no_leads') {
            return ['off', ($r['spend'] ?? 0).' sarflandi, bitta ham '.mb_strtolower(ResultType::SHORT[$ad->resultType()] ?? 'natija')." yo'q", "O'chiring yoki yangi kreativga almashtiring"];
        }

        $freq = (float) $ad->frequency_7d;
        $pct = fn ($now, $before) => ($now !== null && $before) ? ($now / $before - 1) * 100 : 0;

        // candidate causes with a "how bad" score; the worst one wins
        $causes = [];
        if ($ad->resultType() === 'leads' && $pct($r['conv'] ?? null, $b['conv'] ?? null) < -20) {
            $causes['conv'] = [abs($pct($r['conv'], $b['conv'])), "Lendingda lid kamaydi ({$b['conv']}% → {$r['conv']}%)", 'Lending va formani tekshiring'];
        }
        if ($pct($r['ctr'] ?? null, $b['ctr'] ?? null) < -15) {
            $causes['ctr'] = [abs($pct($r['ctr'], $b['ctr'])), "Kamroq bosishyapti (CTR {$b['ctr']}% → {$r['ctr']}%)", 'Yangi rasm yoki sarlavha sinang'];
        }
        if ($ad->is_video && $ad->resultType() !== 'traffic' && isset($r['hook']) && $r['hook'] < $c['hook_bad']) {
            $causes['hook'] = [($c['hook_bad'] - $r['hook']) / $c['hook_bad'] * 100 + 20, "Video boshi kuchsiz (hook {$r['hook']}%)", 'Videoning birinchi 3 soniyasini almashtiring'];
        }
        if ($ad->is_video && $ad->resultType() !== 'traffic' && isset($r['hold']) && $r['hold'] < $c['hold_bad']) {
            $causes['hold'] = [($c['hold_bad'] - $r['hold']) / $c['hold_bad'] * 100, "Tomoshabin video o'rtasida ketyapti (hold {$r['hold']}%)", 'Videoni qisqartiring'];
        }
        if ($pct($r['cpm'] ?? null, $b['cpm'] ?? null) > 25) {
            $causes['cpm'] = [$pct($r['cpm'], $b['cpm']), "Ko'rsatuv qimmatlashdi (CPM {$b['cpm']} → {$r['cpm']})", 'Auditoriyani kengaytiring'];
        }
        if ($ad->resultType() !== 'traffic' && ($freq >= $c['frequency_warn'] || $type === 'frequency')) {
            $causes['freq'] = [($freq - 1) * 20, "Auditoriya charchadi (chastota {$freq})", 'Yangi kreativ qo\'shing'];
        }

        if (in_array($ad->resultType(), ['traffic', 'messages'], true) && $change !== null) {
            $norm = $ad->resultType() === 'traffic' ? $c['traffic_cpc_good'] : ($c['message_cost_good'] ?? null);
            $causes['cpc'] = [1, ($ad->resultType() === 'traffic' ? 'Klik' : 'Xabar').' narxi normadan yuqori'.($norm ? ' (norma ≤ $'.$norm.')' : ''), 'Yangi rasm/matn sinang yoki auditoriyani o\'zgartiring'];
        }

        if ($causes) {
            uasort($causes, fn ($x, $y) => $y[0] <=> $x[0]);
            [, $cause, $action] = array_values($causes)[0];
        } else {
            [$cause, $action] = ["Aniq sabab yo'q, narx o'zgarib turibdi", 'Bir-ikki kun kuzating'];
        }

        if ($change !== null && $change >= 100) {
            return ['off', $cause, "To'xtating; "."yaxshilanmasa o'chiring. ".$action];
        }

        return [$type === 'frequency' ? 'tired' : 'expensive', $cause, $action];
    }
}
