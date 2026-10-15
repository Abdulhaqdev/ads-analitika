<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\FacebookAdAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Reklama yoqish": turns past results of a product into a ready campaign plan
 * (best ad sets with their exact targeting, best creatives, budget, exclusions).
 */
class LaunchPlanner
{
    private const ADSET_FIELDS = 'name,targeting,optimization_goal,billing_event,bid_strategy,promoted_object,destination_type,attribution_spec,daily_budget,campaign{objective,buying_type}';

    public function __construct(private AnalyticsQuery $q, private CampaignAnalysis $analysis, private FacebookGraph $graph) {}

    /**
     * Products (campaign families) of the account with spend, results and cost, best first.
     *
     * @return Collection<int, array{key: string, spend: float, results: int, cost: float|null, campaigns: int, active: int, type: string}>
     */
    public function products(FacebookAdAccount $account, DateRange $range): Collection
    {
        $campaigns = $this->q->campaigns($range->from, $range->to, $account->id);
        $active = Ad::where('facebook_ad_account_id', $account->id)->where('effective_status', 'ACTIVE')
            ->pluck('campaign_name')->map(fn ($n) => $this->productKey((string) $n))->countBy();

        return $campaigns->groupBy(fn ($c) => $this->productKey($c['name']))
            ->map(function ($g, $key) use ($active) {
                $spend = $g->sum(fn ($c) => $c['m']['spend']);
                $results = (int) $g->sum(fn ($c) => $c['m']['leads']);

                return [
                    'key' => $key,
                    'spend' => round($spend, 2),
                    'results' => $results,
                    'cost' => $results > 0 ? round($spend / $results, 2) : null,
                    'campaigns' => $g->count(),
                    'active' => (int) ($active[$key] ?? 0),
                    'type' => $g->sortByDesc(fn ($c) => $c['m']['spend'])->first()['m']['rtype'] ?? 'leads',
                ];
            })
            ->filter(fn ($p) => $p['spend'] >= 5)
            ->sortBy(fn ($p) => $p['results'] >= 10 && $p['cost'] !== null ? $p['cost'] : 9999 - $p['results'])
            ->values();
    }

    /**
     * Launch settings from the form (query string or POST), clamped to sane values.
     *
     * @param  array<string, mixed>  $input
     * @return array{adsets: int, videos: int, budget: float|null, structure: string, split: bool, link: string|null}
     */
    public static function options(array $input): array
    {
        $link = trim((string) ($input['link'] ?? ''));

        return [
            'adsets' => min(8, max(1, (int) ($input['adsets'] ?? 4))),
            'videos' => min(8, max(1, (int) ($input['videos'] ?? 4))),
            'budget' => is_numeric($input['budget'] ?? null) ? max(1.0, (float) $input['budget']) : null,
            'structure' => ($input['structure'] ?? 'cbo') === 'abo' ? 'abo' : 'cbo',
            'split' => filter_var($input['split'] ?? false, FILTER_VALIDATE_BOOL),
            'link' => filter_var($link, FILTER_VALIDATE_URL) ? $link : null,
            'dest' => ($input['dest'] ?? 'site') === 'form' ? 'form' : 'site',
            'form' => preg_match('/^\d{6,20}$/', trim((string) ($input['form'] ?? ''))) ? trim((string) $input['form']) : null,
            // ad sets / videos ticked by hand on the plan; null keeps the automatic top picks
            'pick_adsets' => self::ids($input['pick_adsets'] ?? null),
            'pick_videos' => self::ids($input['pick_videos'] ?? null),
        ];
    }

    /** @return list<string>|null */
    private static function ids(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $ids = array_values(array_filter(array_map('strval', $value), fn ($v) => preg_match('/^\d{5,25}$/', $v)));

        return $ids ?: null;
    }

    /** How many alternatives the plan offers to choose from. */
    private const CHOICES = 8;

    private const VIDEO_CHOICES = 20;

    /** Ad sets that send people to an instant form instead of a website. */
    private function isFormAdset(array $setup): bool
    {
        return ($setup['destination_type'] ?? null) === 'ON_AD'
            || in_array($setup['optimization_goal'] ?? null, ['LEAD_GENERATION', 'QUALITY_LEAD'], true);
    }

    /**
     * @param  array{adsets?: int, videos?: int, budget?: float|null, structure?: string, split?: bool, link?: string|null}  $opts
     * @return array<string, mixed>|null null when nothing matched the product name
     */
    public function plan(FacebookAdAccount $account, string $product, DateRange $range, array $opts = []): ?array
    {
        // a product from the ranking is a cleaned name ("m s cefr" from "M/S CEFR 30$"): match its campaigns by that key too
        $key = mb_strtolower(trim($product));
        $names = $this->q->campaigns($range->from, $range->to, $account->id)
            ->filter(fn ($c) => $this->productKey($c['name']) === $key)->pluck('name')->all();
        $data = $this->analysis->build($account, [$product, ...$names], $range);
        if ($data['campaigns']->isEmpty() || $data['ads']->isEmpty()) {
            return null;
        }

        $cost = $data['cost'];
        $minRes = max(3, min(15, (int) round($data['results'] / 25)));
        $token = $account->connection->access_token;

        // ad sets: the cheapest ones with real volume, one per distinct targeting
        $adsets = $data['ads']->groupBy(fn ($r) => $r['ad']->adset_id)->map(function ($g, $id) {
            $spend = $g->sum(fn ($r) => $r['m']['spend']);
            $results = (int) $g->sum(fn ($r) => $r['m']['leads']);

            return ['id' => (string) $id, 'name' => $g->first()['ad']->adset_name, 'campaign' => $g->first()['ad']->campaign_name,
                'spend' => round($spend, 2), 'results' => $results, 'cost' => $results > 0 ? round($spend / $results, 2) : null];
        })->filter(fn ($a) => $a['id'] && $a['cost'] !== null && $a['results'] >= $minRes)->sortBy('cost')->values();

        $dest = ($opts['dest'] ?? 'site') === 'form' ? 'form' : 'site';
        $details = $this->details($adsets->take(25)->pluck('id')->all(), self::ADSET_FIELDS, $token);
        // the audiences are taken from ad sets that already used the chosen destination, if there are any
        $sameDest = collect($details)->contains(fn ($d) => $this->isFormAdset($d) === ($dest === 'form'));
        $seen = [];
        $picked = collect();
        foreach ($adsets as $a) {
            $d = $details[$a['id']] ?? null;
            if (! $d || empty($d['targeting']) || ($sameDest && $this->isFormAdset($d) !== ($dest === 'form'))) {
                continue;
            }
            $signature = $this->signature($d['targeting']);
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $picked->push($a + ['label' => $this->cleanName($a['name']), 'targeting' => $d['targeting'], 'summary' => $this->summary($d['targeting']), 'source' => $d]);
            if ($picked->count() >= max(self::CHOICES, $opts['adsets'] ?? 4)) {
                break;
            }
        }

        // more candidates than needed are shown; the top ones are ticked unless the user ticked others
        $adsetChoices = $picked;
        $picked = $this->choose($adsetChoices, 'id', $opts['pick_adsets'] ?? null, (int) ($opts['adsets'] ?? 4));
        // every video with spend is listed (most results first); the cheapest proven ones are ticked by default
        $videoChoices = $this->creatives($data['ads'], $minRes, self::VIDEO_CHOICES, $token, $dest);
        $proven = $videoChoices->where('proven', true)->sortBy('cost')->values();
        $topAds = ($proven->isNotEmpty() ? $proven : $videoChoices)->take(max(1, (int) ($opts['videos'] ?? 4)))->values();
        $hand = collect($opts['pick_videos'] ?? [])->isNotEmpty()
            ? $videoChoices->filter(fn ($c) => in_array($c['ad_id'], $opts['pick_videos'], true))->sortBy('cost')->values() : collect();
        if ($hand->isNotEmpty()) {
            $topAds = $hand;
        }

        // lead forms seen in this product's ads (most used first), then the page's other active forms;
        // the chosen one goes on every ad
        $pageId = $topAds->pluck('page_id')->filter()->first();
        $pageForms = $dest === 'form' && $pageId ? $this->pageForms((string) $pageId, $token) : [];
        $used = $topAds->whereNotNull('form_id')->groupBy('form_id')
            ->map(fn ($g, $id) => ['id' => (string) $id, 'name' => $pageForms[$id]['name'] ?? null, 'results' => $g->sum('results'), 'videos' => $g->count()])
            ->sortByDesc('results')->values();
        // the page returns its newest forms first; a dozen is enough to pick from, older ones go by ID
        $forms = $used->concat(collect($pageForms)->reject(fn ($f) => $used->contains('id', $f['id']) || $f['status'] !== 'ACTIVE')
            ->take(12)->map(fn ($f) => ['id' => $f['id'], 'name' => $f['name'], 'results' => 0, 'videos' => 0])->values());
        if (isset($opts['form']) && ! $forms->contains('id', $opts['form']) && isset($pageForms[$opts['form']])) {
            $forms->push(['id' => $opts['form'], 'name' => $pageForms[$opts['form']]['name'], 'results' => 0, 'videos' => 0]);
        }
        $form = $dest === 'form' ? ($opts['form'] ?? $used->first()['id'] ?? null) : null;

        $setup = function (array $source) use ($dest, $pageId) {
            $s = collect($source)->only(['optimization_goal', 'billing_event', 'promoted_object', 'destination_type', 'attribution_spec'])->all();
            if ($dest === 'form' && ! $this->isFormAdset($source)) {
                // a website ad set turned into an instant-form one: same audience, form delivery
                $s = ['optimization_goal' => 'LEAD_GENERATION', 'billing_event' => 'IMPRESSIONS', 'destination_type' => 'ON_AD', 'promoted_object' => ['page_id' => $pageId]];
            }

            return $s;
        };

        $budgets = $data['ads']->map(fn ($r) => (float) $r['ad']->adset_daily_budget)->filter()->sort()->values();
        $structure = ($opts['structure'] ?? 'abo') === 'cbo' ? 'cbo' : 'abo';
        // CBO: one budget for the whole campaign; ABO: the usual per-adset budget of this product
        $budget = $opts['budget'] ?? ($structure === 'cbo' ? 20.0 : ($budgets->isNotEmpty() ? max(5.0, round($budgets->median())) : 15.0));
        $split = (bool) ($opts['split'] ?? false);
        $link = $dest === 'form' ? null : (trim((string) ($opts['link'] ?? '')) ?: ($topAds->first()['link'] ?? null));

        $first = $picked->first()['source'] ?? [];
        $existingExclusions = $picked->flatMap(fn ($a) => collect($a['targeting']['excluded_custom_audiences'] ?? [])->pluck('id'))->unique()->values()->all();
        $audiences = $this->customAudiences($account, $existingExclusions);
        $act = $account->account_id;
        $filter = fn (array $ids, string $key) => rawurlencode("{$key}-STRING_SET\u{1E}ANY\u{1E}".json_encode(array_values($ids)));

        return [
            'product' => $product,
            'range' => $range->label(),
            'type' => $data['type'],
            'spend' => $data['spend'],
            'results' => $data['results'],
            'cost' => $cost,
            'campaigns' => $data['campaigns']->count(),
            'objective' => $first['campaign']['objective'] ?? null,
            'optimization' => $first ? ($setup($first)['optimization_goal'] ?? null) : null,
            'promoted' => $first ? ($setup($first)['promoted_object'] ?? null) : null,
            'adsets' => $picked->map(fn ($a) => collect($a)->except('source')->all() + ['setup' => $setup($a['source']), 'objective' => $a['source']['campaign']['objective'] ?? null])->all(),
            'dest' => $dest,
            'forms' => $forms->all(),
            'form' => $form,
            'formChanged' => $form !== null && $topAds->contains(fn ($c) => $c['form_id'] !== $form),
            'creatives' => $topAds->all(),
            'adsetChoices' => $adsetChoices->map(fn ($a) => collect($a)->only(['id', 'label', 'campaign', 'cost', 'results', 'summary'])->all()
                + ['selected' => $picked->contains('id', $a['id'])])->values()->all(),
            'videoChoices' => $videoChoices->map(fn ($c) => $c + ['selected' => $topAds->contains('ad_id', $c['ad_id'])])->values()->all(),
            'budget' => $budget,
            'structure' => $structure,
            'split' => $split,
            'daily' => $this->daily($structure, $budget, $picked->count(), $split ? $topAds->count() : 1),
            'link' => $link,
            'linkChanged' => $link !== null && $topAds->contains(fn ($c) => $c['link'] !== $link),
            'audiences' => $audiences,
            // Ads Manager views filtered to exactly these ad sets / ads: select all → "Duplicate" → "New campaign"
            'adsetsUrl' => $picked->isEmpty() ? null : "https://adsmanager.facebook.com/adsmanager/manage/adsets?act={$act}&filter_set=".$filter($picked->pluck('id')->all(), 'SEARCH_BY_CAMPAIGN_IDS'),
            'adsUrl' => $topAds->isEmpty() ? null : "https://adsmanager.facebook.com/adsmanager/manage/ads?act={$act}&filter_set=".$filter($topAds->pluck('ad_id')->all(), 'SEARCH_BY_ADGROUP_IDS'),
            'text' => $this->text($product, $picked, $topAds, $budget, $first, $audiences, $structure, $split, $link),
            'insights' => $data['insights'],
            'genders' => $data['genders']->all(),
            'ages' => $data['ages']->all(),
            'brief' => $data['brief'],
            'error' => $data['error'],
        ];
    }

    /**
     * Best creatives, one per video. Ad names repeat across campaigns ("1", "4"…) while the
     * video differs, so ads are grouped by the video they actually show and judged together.
     *
     * @param  Collection<int, array<string, mixed>>  $ads
     * @return Collection<int, array<string, mixed>>
     */
    private function creatives(Collection $ads, int $minRes, int $take, string $token, string $dest): Collection
    {
        $candidates = $ads->filter(fn ($r) => $r['m']['spend'] > 0)->sortByDesc(fn ($r) => $r['m']['leads'])->take(80)->values();
        $details = $this->details($candidates->map(fn ($r) => (string) $r['ad']->ad_id)->all(),
            'creative{id,object_story_spec{page_id,video_data{video_id,call_to_action},link_data{call_to_action}}}', $token);
        $form = fn ($r) => $this->cta($details[(string) $r['ad']->ad_id]['creative'] ?? [])['value']['lead_gen_form_id'] ?? null;

        // ads that already lead where we are going are reused as they are; others only when there are none
        $matching = $candidates->filter(fn ($r) => ($dest === 'form') === (bool) $form($r));
        if ($matching->isNotEmpty()) {
            $candidates = $matching->values();
        }

        return $candidates->groupBy(function ($r) use ($details) {
            $creative = $details[(string) $r['ad']->ad_id]['creative'] ?? [];

            return $creative['object_story_spec']['video_data']['video_id'] ?? 'ad:'.$r['ad']->name.'|'.$r['ad']->creative_title;
        })->map(function ($g, $key) use ($details, $minRes) {
            $spend = $g->sum(fn ($r) => $r['m']['spend']);
            $results = (int) $g->sum(fn ($r) => $r['m']['leads']);
            // rates are re-weighted over all ads of the video, not averaged per ad
            $impr = $g->sum(fn ($r) => $r['m']['impressions']);
            $clicks = $g->sum(fn ($r) => $r['m']['link_clicks']);
            $v3s = $g->sum(fn ($r) => ($r['m']['hook'] ?? 0) * $r['m']['impressions'] / 100);
            $thru = $g->sum(fn ($r) => $r['m']['thruplays']);
            // the newest ad carries the creative as it was last approved (latest link and text)
            $latest = $g->sortByDesc(fn ($r) => (string) $r['ad']->ad_id)->first();
            $creative = $details[(string) $latest['ad']->ad_id]['creative'] ?? [];

            return [
                'ad_id' => (string) $latest['ad']->ad_id,
                'creative_id' => $creative['id'] ?? null,
                'video_id' => str_starts_with((string) $key, 'ad:') ? null : (string) $key,
                'name' => $latest['ad']->name,
                'thumb' => $latest['ad']->thumbnail_url,
                'is_video' => (bool) $latest['ad']->is_video,
                'campaign' => $latest['ad']->campaign_name,
                'cost' => $results > 0 ? round($spend / $results, 2) : null,
                'results' => $results,
                'spend' => round($spend, 2),
                'ads' => $g->count(),
                'impressions' => $impr,
                'ctr' => $impr > 0 ? round($clicks / $impr * 100, 2) : null,
                'cpm' => $impr > 0 ? round($spend / $impr * 1000, 2) : null,
                'hook' => $impr > 0 && $v3s > 0 ? round($v3s / $impr * 100, 1) : null,
                'hold' => $v3s > 0 && $thru > 0 ? round($thru / $v3s * 100, 1) : null,
                // enough results to trust its cost; only these are picked automatically
                'proven' => $results > 0 && $results >= $minRes,
                'link' => $this->cta($creative)['value']['link'] ?? $latest['ad']->link_url,
                'form_id' => $this->cta($creative)['value']['lead_gen_form_id'] ?? null,
                'page_id' => $creative['object_story_spec']['page_id'] ?? null,
                'title' => $latest['ad']->creative_title,
            ];
        })->filter(fn ($c) => $c['spend'] > 0)
            ->sortByDesc('results')->take(max(1, $take))->values();
    }

    /**
     * The page's lead forms by id. Forms are read with the page's own token, which the user token
     * yields once the page permissions are granted; without them the list is simply empty.
     *
     * @return array<string, array{id: string, name: string, status: string}>
     */
    private function pageForms(string $pageId, string $token): array
    {
        // only a real list is cached, so forms show up right after the permissions are granted
        if ($cached = Cache::get("page-forms:{$pageId}")) {
            return $cached;
        }

        try {
            $pageToken = $this->graph->get($pageId, ['fields' => 'access_token'], $token)['access_token'] ?? null;
            if (! $pageToken) {
                return [];
            }

            $forms = collect($this->graph->paginate("{$pageId}/leadgen_forms", ['fields' => 'id,name,status', 'limit' => 100], $pageToken))
                ->mapWithKeys(fn ($f) => [(string) $f['id'] => ['id' => (string) $f['id'], 'name' => $f['name'] ?? $f['id'], 'status' => $f['status'] ?? '']])
                ->all();
        } catch (\Throwable) {
            return [];
        }

        Cache::put("page-forms:{$pageId}", $forms, now()->addMinutes(30));

        return $forms;
    }

    /**
     * The ticked items in their ranking order, or the first $take when nothing ticked matches.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  list<string>|null  $picks
     * @return Collection<int, array<string, mixed>>
     */
    private function choose(Collection $items, string $key, ?array $picks, int $take): Collection
    {
        $chosen = $picks ? $items->filter(fn ($i) => in_array((string) $i[$key], $picks, true))->values() : collect();

        return $chosen->isNotEmpty() ? $chosen : $items->take(max(1, $take))->values();
    }

    /** @return array<string, mixed> the creative's button: where it leads (link or lead form) */
    private function cta(array $creative): array
    {
        $story = $creative['object_story_spec'] ?? [];

        return $story['video_data']['call_to_action'] ?? $story['link_data']['call_to_action'] ?? [];
    }

    /**
     * Graph batch lookup keyed by object id.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function details(array $ids, string $fields, string $token): array
    {
        // rows are cached so the plan shown on the page and the one prepared on submit are the same,
        // even when Graph hits its rate limit in between
        $key = fn ($id) => 'graph-detail:'.md5($fields).":{$id}";
        $out = [];
        $missing = [];
        foreach (array_values(array_unique(array_filter($ids))) as $id) {
            ($row = Cache::get($key($id))) ? $out[$id] = $row : $missing[] = $id;
        }

        foreach (array_chunk($missing, 50) as $chunk) {
            try {
                foreach ($this->graph->batch(array_map(fn ($id) => $id.'?fields='.urlencode($fields), $chunk), $token) as $row) {
                    $out[$row['id']] = $row;
                    Cache::put($key($row['id']), $row, now()->addMinutes(30));
                }
            } catch (\Throwable) {
                // a missing permission or deleted object only removes that row
            }
        }

        return $out;
    }

    /**
     * Account custom audiences for the exclusion list; preselected: what the best ad sets already
     * excluded plus long-window engagement/website audiences.
     *
     * @param  list<string>  $existing
     * @return list<array{id: string, name: string, subtype: string, selected: bool}>
     */
    private function customAudiences(FacebookAdAccount $account, array $existing): array
    {
        $rows = Cache::remember("custom-aud:{$account->id}", now()->addMinutes(10), function () use ($account) {
            try {
                return $this->graph->paginate('act_'.$account->account_id.'/customaudiences', ['fields' => 'id,name,subtype,retention_days'], $account->connection->access_token);
            } catch (\Throwable) {
                return [];
            }
        });

        return collect($rows)->reject(fn ($a) => ($a['subtype'] ?? '') === 'LOOKALIKE')->map(fn ($a) => [
            'id' => (string) $a['id'],
            'name' => $a['name'] ?? $a['id'],
            'subtype' => $a['subtype'] ?? '',
            'selected' => in_array((string) $a['id'], $existing, true) || str_contains(mb_strtolower($a['name'] ?? ''), '180d'),
        ])->sortByDesc('selected')->values()->all();
    }

    /**
     * The plan as plain text, to follow step by step in Ads Manager.
     *
     * @param  Collection<int, array<string, mixed>>  $adsets
     * @param  Collection<int, array<string, mixed>>  $creatives
     * @param  array<string, mixed>  $setup
     * @param  list<array<string, mixed>>  $audiences
     */
    private function text(string $product, Collection $adsets, Collection $creatives, float $budget, array $setup, array $audiences, string $structure, bool $split, ?string $link): string
    {
        $money = fn ($v) => $v === null ? '—' : '$'.number_format((float) $v, 2);
        $lines = ["Kampaniya: {$product} / ".now()->format('d.m').($split ? ' — har video alohida kampaniya' : ''),
            'Maqsad: '.($setup['campaign']['objective'] ?? '—').' · optimallashtirish: '.($setup['optimization_goal'] ?? '—')
            .(isset($setup['promoted_object']['custom_event_type']) ? ' · event: '.$setup['promoted_object']['custom_event_type'] : ''),
            $structure === 'cbo'
                ? "Byudjet: kampaniya darajasida (CBO), kuniga {$money($budget)}, narx chegarasiz (bid cap yo'q)"
                : "Byudjet: adset darajasida (ABO), har biri kuniga {$money($budget)}, narx chegarasiz (bid cap yo'q)", ''];

        foreach ($adsets->values() as $i => $a) {
            $t = $a['targeting'];
            $interests = collect($t['flexible_spec'] ?? [])->flatMap(fn ($s) => collect($s)->flatten(1)->pluck('name'))->filter()->implode(', ');
            $lines[] = ($i + 1).". Adset \"{$a['label']}\" — o'tgan natija {$money($a['cost'])}, {$a['results']} ta";
            $lines[] = '   '.$a['summary'];
            if ($interests) {
                $lines[] = "   Qiziqishlar: {$interests}";
            }
        }

        $excluded = collect($audiences)->where('selected', true)->pluck('name');
        if ($excluded->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Istisno auditoriyalar: '.$excluded->implode(', ');
        }

        $lines[] = '';
        $lines[] = 'Kreativlar (har bir adsetga):';
        foreach ($creatives as $c) {
            $lines[] = "• {$c['name']} — {$money($c['cost'])}, {$c['results']} ta".($c['title'] ? " · sarlavha: {$c['title']}" : '');
        }
        if ($link) {
            $lines[] = "Havola: {$link}";
        }

        return implode("\n", $lines);
    }

    /** Total daily spend of everything the plan creates. */
    private function daily(string $structure, float $budget, int $adsets, int $campaigns): float
    {
        return round(($structure === 'cbo' ? $budget : $budget * $adsets) * $campaigns, 2);
    }

    /** Ad sets with the same interests and demographics are the same audience. */
    private function signature(array $t): string
    {
        $interests = collect($t['flexible_spec'] ?? [])->flatMap(fn ($s) => collect($s)->flatten(1)->pluck('id'))->sort()->implode(',');

        $countries = collect($t['geo_locations']['countries'] ?? [])->sort()->implode(',');

        return md5(implode('|', [$interests, $t['age_min'] ?? '', $t['age_max'] ?? '', collect($t['genders'] ?? [])->sort()->implode(','), $countries]));
    }

    /**
     * Product name hidden in a campaign name: "4 / Bolalar palatka / 11$ — 1" → "bolalar palatka".
     * Numbers, budgets, dates and setup words (abo, cbo, bid, new…) are not the product.
     */
    public function productKey(string $name): string
    {
        foreach (preg_split('/[\/|]/u', mb_strtolower($name)) as $part) {
            $part = preg_replace('/\b(new|abo|cbo|bid|test|lid|lead|cost|per|copy|копия|video|ves srok|ves srog|denamic|dinamic)\b/u', ' ', $part);
            $part = preg_replace('/[\d$*#.,:\-–—()]+/u', ' ', (string) $part);
            $part = trim((string) preg_replace('/\s+/u', ' ', (string) $part));

            if (mb_strlen((string) preg_replace('/[^\p{L}]/u', '', $part)) >= 3) {
                return $part;
            }
        }

        return $this->analysis->family($name);
    }

    private function summary(array $t): string
    {
        $parts = [];
        if (isset($t['age_min'])) {
            $parts[] = ($t['age_min'] ?? 18).'–'.($t['age_max'] ?? 65).' yosh';
        }
        $g = array_values(array_intersect($t['genders'] ?? [], [1, 2]));
        $parts[] = count($g) === 1 ? [1 => 'erkak', 2 => 'ayol'][$g[0]] : 'hamma jins';
        $interests = collect($t['flexible_spec'] ?? [])->flatMap(fn ($s) => collect($s)->flatten(1)->pluck('name'))->filter()->take(4);
        $parts[] = $interests->isNotEmpty() ? $interests->implode(', ') : 'qiziqishsiz (keng)';
        $parts[] = collect($t['geo_locations']['countries'] ?? [])->implode(', ') ?: 'hudud';
        if (! empty($t['targeting_automation']['advantage_audience'])) {
            $parts[] = 'Advantage+';
        }

        return implode(' · ', $parts);
    }

    private function cleanName(?string $name): string
    {
        return trim((string) preg_replace('/\s*—\s*(Копия|Copy|A\/I).*$/u', '', (string) $name)) ?: 'adset';
    }
}
