<?php

namespace App\Services;

use App\Models\AdGuard;

/**
 * Switches off ads of guarded campaigns whose lead cost went over the limit. Meta's own automated
 * rules cannot do this in CBO campaigns ("rules that turn off ads can't have cost conditions"),
 * so the check runs here.
 */
class AdGuardian
{
    /** Website pixel leads and instant-form leads; the largest one is the ad's lead count. */
    private const LEAD_ACTIONS = ['lead', 'offsite_conversion.fb_pixel_lead', 'onsite_conversion.lead_grouped'];

    public function __construct(private FacebookGraph $graph) {}

    /**
     * Ads that should be switched off under this guard's limit, judged on their whole lifetime.
     *
     * @param  list<array<string, mixed>>  $rows  ad-level insights rows
     * @return list<array{ad_id: string, ad_name: ?string, adset_name: ?string, spend: float, leads: int, cpl: float|null, reason: string}>
     */
    public function verdicts(AdGuard $guard, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $spend = (float) ($row['spend'] ?? 0);
            $leads = (int) collect($row['actions'] ?? [])->whereIn('action_type', self::LEAD_ACTIONS)->max(fn ($a) => (int) $a['value']);
            $cpl = $leads > 0 ? round($spend / $leads, 2) : null;

            // too little spend says nothing yet; with no lead at all, wait until it cost two limits
            $reason = match (true) {
                $spend < $guard->min_spend => null,
                $leads === 0 && $spend >= max($guard->min_spend, $guard->max_cpl * 2) => "lid yo'q, sarf \${$spend}",
                $leads > 0 && $cpl > $guard->max_cpl => "lid narxi \${$cpl} > \${$guard->max_cpl}",
                default => null,
            };

            if ($reason) {
                $out[] = ['ad_id' => (string) $row['ad_id'], 'ad_name' => $row['ad_name'] ?? null, 'adset_name' => $row['adset_name'] ?? null,
                    'spend' => round($spend, 2), 'leads' => $leads, 'cpl' => $cpl, 'reason' => $reason];
            }
        }

        return $out;
    }

    /**
     * Checks one guard and pauses what went over. An ad is paused only once: if the owner switches
     * it back on by hand, that decision is respected.
     *
     * @return list<array<string, mixed>> what was (or in a dry run would be) paused
     */
    public function run(AdGuard $guard, bool $dry = false): array
    {
        $token = $guard->account->connection->access_token;
        $rows = $this->graph->paginate("{$guard->campaign_id}/insights", [
            'level' => 'ad',
            'fields' => 'ad_id,ad_name,adset_name,spend,actions',
            'date_preset' => 'maximum',
            'limit' => 200,
        ], $token);

        $done = $guard->actions()->where('result', 'paused')->pluck('ad_id')->all();
        $paused = [];
        foreach ($this->verdicts($guard, $rows) as $v) {
            if (in_array($v['ad_id'], $done, true)) {
                continue;
            }
            if (! $dry) {
                $this->graph->post($v['ad_id'], ['status' => 'PAUSED'], $token);
                $guard->actions()->create(collect($v)->except('reason')->all() + ['result' => 'paused']);
            }
            $paused[] = $v;
        }

        $dry || $guard->update(['checked_at' => now()]);

        return $paused;
    }

    /**
     * Next daily budget for the campaign, judged on the last three days: +20% while leads cost at most
     * 85% of the limit (up to max_budget), −20% while they cost more than the limit (not under
     * base_budget). One step a day, so Meta's delivery has time to settle between changes.
     *
     * @return array{budget: float, reason: string}|null
     */
    public function budgetStep(AdGuard $guard, float $spend, int $leads, float $current): ?array
    {
        if (! $guard->base_budget || ($guard->scaled_at && $guard->scaled_at->gt(now()->subHours(20))) || $spend < 10) {
            return null;
        }

        $cpl = $leads > 0 ? round($spend / $leads, 2) : null;
        $max = $guard->max_budget ?: $guard->base_budget * 2;

        if ($cpl !== null && $cpl <= $guard->max_cpl * 0.85 && $current < $max) {
            return ['budget' => min($max, round($current * 1.2, 2)), 'reason' => "lid narxi \${$cpl} — arzon, byudjet oshirildi"];
        }
        if (($cpl === null || $cpl > $guard->max_cpl) && $current > $guard->base_budget) {
            $shown = $cpl === null ? "lid yo'q" : "lid narxi \${$cpl}";

            return ['budget' => max($guard->base_budget, round($current * 0.8, 2)), 'reason' => "{$shown} — qimmat, byudjet kamaytirildi"];
        }

        return null;
    }

    /**
     * Applies budgetStep() to the campaign. Returns the change made (or, in a dry run, proposed).
     *
     * @return array{from: float, to: float, reason: string}|null
     */
    public function scale(AdGuard $guard, bool $dry = false): ?array
    {
        if (! $guard->base_budget) {
            return null;
        }

        $token = $guard->account->connection->access_token;
        $range = json_encode(['since' => now()->subDays(2)->toDateString(), 'until' => now()->toDateString()]);
        $row = $this->graph->get("{$guard->campaign_id}/insights", ['fields' => 'spend,actions', 'time_range' => $range], $token)['data'][0] ?? [];
        $spend = (float) ($row['spend'] ?? 0);
        $leads = (int) collect($row['actions'] ?? [])->whereIn('action_type', self::LEAD_ACTIONS)->max(fn ($a) => (int) $a['value']);
        $current = ((int) ($this->graph->get($guard->campaign_id, ['fields' => 'daily_budget'], $token)['daily_budget'] ?? 0)) / 100;

        if ($current <= 0 || ! ($step = $this->budgetStep($guard, $spend, $leads, $current))) {
            return null;
        }

        if (! $dry) {
            $this->graph->post($guard->campaign_id, ['daily_budget' => (int) round($step['budget'] * 100)], $token);
            $guard->actions()->create(['ad_id' => $guard->campaign_id, 'ad_name' => 'kampaniya byudjeti', 'spend' => $spend, 'leads' => $leads,
                'cpl' => $leads > 0 ? round($spend / $leads, 2) : null, 'result' => "budget {$current} → {$step['budget']}"]);
            $guard->update(['scaled_at' => now()]);
        }

        return ['from' => $current, 'to' => $step['budget'], 'reason' => $step['reason']];
    }
}
