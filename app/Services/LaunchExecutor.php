<?php

namespace App\Services;

use App\Models\FacebookAdAccount;

/**
 * Prepares a planned campaign in Ads Manager. The campaign is ALWAYS created paused:
 * nothing is delivered or spent until the owner reviews it and switches it on himself.
 */
class LaunchExecutor
{
    public const PERMISSION = 'ads_management';

    public function __construct(private FacebookGraph $graph) {}

    public function canWrite(FacebookAdAccount $account): bool
    {
        try {
            return in_array(self::PERMISSION, $this->graph->grantedPermissions($account->connection->access_token), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One campaign with every creative in every ad set, or (plan "split") one campaign per creative.
     * CBO puts the budget on the campaign, ABO on each ad set.
     *
     * @param  array<string, mixed>  $plan  from LaunchPlanner::plan()
     * @return array{ok: bool, campaign_id: string|null, campaigns: list<string>, adsets: int, ads: int, error: string|null, warning: string|null}
     */
    public function prepare(FacebookAdAccount $account, array $plan, string $name): array
    {
        $token = $account->connection->access_token;
        $act = 'act_'.$account->account_id;
        $result = ['ok' => false, 'campaign_id' => null, 'campaigns' => [], 'adsets' => 0, 'ads' => 0, 'error' => null, 'warning' => null];

        try {
            if (! $plan['adsets'] || ! $plan['creatives']) {
                throw new \RuntimeException("Rejada adset yoki kreativ yo'q.");
            }

            // proven creatives are reused exactly as they ran, except for the link when another one is asked for
            $creatives = [];
            foreach (array_values($plan['creatives']) as $i => $c) {
                $id = $c['creative_id'] ?? null;
                $id ??= $this->graph->get($c['ad_id'], ['fields' => 'creative{id}'], $token)['creative']['id'] ?? null;
                if (! $id) {
                    // stop before anything is created rather than launch with fewer videos than chosen
                    throw new \RuntimeException("Video \"{$c['name']}\" kreativini Facebook'dan olib bo'lmadi. Birozdan keyin qayta urinib ko'ring.");
                }
                if (($plan['dest'] ?? 'site') === 'form') {
                    if (! empty($plan['form']) && ($c['form_id'] ?? null) !== $plan['form']) {
                        [$id, $warning] = $this->withDestination($act, $id, ['form' => $plan['form']], $token);
                        // a website creative cannot run in an instant-form ad set, so that one has to stop here
                        if ($warning && empty($c['form_id'])) {
                            throw new \RuntimeException("Video \"{$c['name']}\" saytga olib boradi, lid formaga o'tkazib bo'lmadi: {$warning}");
                        }
                        $result['warning'] ??= $warning;
                    }
                } elseif (! empty($plan['link']) && ($c['link'] ?? null) !== $plan['link']) {
                    [$id, $warning] = $this->withDestination($act, $id, ['link' => $plan['link']], $token);
                    $result['warning'] ??= $warning;
                }
                $creatives[] = ['name' => 'video '.($i + 1), 'id' => $id];
            }
            if (! $creatives) {
                throw new \RuntimeException('Kreativlarni olib bo\'lmadi.');
            }

            $cbo = ($plan['structure'] ?? 'abo') === 'cbo';
            $groups = ! empty($plan['split']) ? array_map(fn ($c) => [$c], $creatives) : [$creatives];
            $exclusions = collect($plan['audiences'])->where('selected', true)->pluck('id');

            foreach ($groups as $set) {
                $campaign = $this->graph->post("{$act}/campaigns", array_filter([
                    'name' => count($groups) > 1 ? "{$name} / {$set[0]['name']}" : $name,
                    'objective' => $plan['objective'] ?: 'OUTCOME_LEADS',
                    'status' => 'PAUSED',
                    'buying_type' => 'AUCTION',
                    'special_ad_categories' => [],
                    'daily_budget' => $cbo ? (int) round($plan['budget'] * 100) : null,
                    'bid_strategy' => $cbo ? 'LOWEST_COST_WITHOUT_CAP' : null,
                    'is_adset_budget_sharing_enabled' => $cbo ? null : 'false',
                ], fn ($v) => $v !== null), $token);
                $result['campaign_id'] ??= $campaign['id'];
                $result['campaigns'][] = $campaign['id'];

                foreach ($plan['adsets'] as $a) {
                    // a WhatsApp customer base can be set in Ads Manager only; the API refuses it with (#3)
                    $targeting = collect($a['targeting'])->except(['subscriber_universe'])->all();
                    $excluded = collect($targeting['excluded_custom_audiences'] ?? [])->pluck('id')->merge($exclusions)->unique()->values();
                    if ($excluded->isNotEmpty()) {
                        $targeting['excluded_custom_audiences'] = $excluded->map(fn ($id) => ['id' => (string) $id])->all();
                    }

                    $adset = $this->graph->post("{$act}/adsets", array_filter([
                        'name' => $a['label'],
                        'campaign_id' => $campaign['id'],
                        'daily_budget' => $cbo ? null : (int) round($plan['budget'] * 100),
                        'billing_event' => $a['setup']['billing_event'] ?? 'IMPRESSIONS',
                        'optimization_goal' => $a['setup']['optimization_goal'] ?? 'OFFSITE_CONVERSIONS',
                        'bid_strategy' => $cbo ? null : 'LOWEST_COST_WITHOUT_CAP',
                        'promoted_object' => $a['setup']['promoted_object'] ?? null,
                        'destination_type' => $a['setup']['destination_type'] ?? null,
                        'attribution_spec' => $a['setup']['attribution_spec'] ?? null,
                        'targeting' => $targeting,
                        'status' => 'ACTIVE', // inside a paused campaign: runs only after the campaign is switched on
                    ], fn ($v) => $v !== null), $token);
                    $result['adsets']++;

                    foreach ($set as $c) {
                        $this->graph->post("{$act}/ads", [
                            'name' => $c['name'],
                            'adset_id' => $adset['id'],
                            'creative' => ['creative_id' => $c['id']],
                            'status' => 'ACTIVE',
                        ], $token);
                        $result['ads']++;
                    }
                }
            }

            $result['ok'] = true;
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * A copy of the creative leading to another link or lead form. Meta refuses new creatives from
     * an app still in development mode; then the original creative is kept and the returned
     * warning tells the owner to change it in Ads Manager.
     *
     * @param  array{link?: string, form?: string}  $to
     * @return array{0: string, 1: string|null}
     */
    private function withDestination(string $act, string $creativeId, array $to, string $token): array
    {
        try {
            $spec = $this->graph->get($creativeId, ['fields' => 'object_story_spec,url_tags'], $token);
            $story = $spec['object_story_spec'] ?? [];
            foreach (['video_data', 'link_data'] as $kind) {
                if (! isset($story[$kind])) {
                    continue;
                }
                if (isset($to['form'])) {
                    $story[$kind]['call_to_action'] = [
                        'type' => $story[$kind]['call_to_action']['type'] ?? 'LEARN_MORE',
                        'value' => ['lead_gen_form_id' => $to['form']],
                    ];
                } elseif (isset($story[$kind]['call_to_action']['value']['link'])) {
                    $story[$kind]['call_to_action']['value']['link'] = $to['link'];
                }
                if ($kind === 'link_data' && isset($to['link'])) {
                    $story[$kind]['link'] = $to['link'];
                }
                if (! empty($story[$kind]['image_hash'])) {
                    unset($story[$kind]['image_url']);
                }
            }

            $copy = $this->graph->post("{$act}/adcreatives", array_filter([
                'name' => 'Launch / '.$creativeId,
                'object_story_spec' => $story,
                'url_tags' => $spec['url_tags'] ?? null,
            ]), $token);

            return [$copy['id'], null];
        } catch (\Throwable $e) {
            return [$creativeId, isset($to['form'])
                ? "Lid forma almashtirilmadi ({$e->getMessage()}). Ads Manager'da reklamalarni belgilab, kerakli formani tanlang."
                : "Havola almashtirilmadi ({$e->getMessage()}). Ads Manager'da reklamalarni belgilab, \"URL сайта\" maydoniga havolani qo'ying."];
        }
    }
}
