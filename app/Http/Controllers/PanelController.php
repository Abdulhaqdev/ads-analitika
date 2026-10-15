<?php

namespace App\Http\Controllers;

use App\Models\AdAlert;
use App\Models\FacebookAdAccount;
use App\Services\AccountReport;
use App\Services\AdCards;
use App\Services\AdMetrics;
use App\Services\AnalyticsQuery;
use App\Services\CampaignAnalysis;
use App\Services\DateRange;
use App\Services\LaunchExecutor;
use App\Services\LaunchPlanner;
use App\Services\LiveBreakdown;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class PanelController extends Controller
{
    private const GENDERS = ['male' => 'Erkak', 'female' => 'Ayol', 'unknown' => "Noma'lum"];

    public function show(Request $request, FacebookAdAccount $account, string $tab, AnalyticsQuery $q, AccountReport $reports, LiveBreakdown $live): View
    {
        abort_unless($account->is_tracked, 404);

        $range = DateRange::fromRequest($request);
        $id = $account->id;
        $from = $range->from;
        $to = $range->to;
        $get = fn (string $kind, array $dims) => $this->live($live, $q, $account, $kind, $dims, $range);
        $sub = 'Tanlangan davr: '.$range->label().'.';

        return match ($tab) {
            'overview', 'statuses', 'reasons', 'recommendations' => $this->overview($account, $reports, $q, $range, $tab),
            'campaigns' => view('panels.campaigns', ['account' => $account, 'campaigns' => $q->campaigns($from, $to, $id)]),
            'ads' => view('panels.ads', ['account' => $account, 'ads' => $q->ads($from, $to, $id), 'all' => $request->boolean('all')]),
            'video' => $this->video($account, $q, $from, $to, $id),
            'campaign-analysis' => $this->campaignAnalysis($request, $account, $range),
            'launch' => $this->launch($request, $account),
            'placement' => $this->breakdown('Joylashuv (placement)', "Facebook/Instagram lentasi, Stories, Reels va boshqa joylar. {$sub}", $get('placement', ['platform', 'position'])
                ->map(fn ($r) => ['label' => ucfirst($r['platform'] ?? '?').' · '.str_replace('_', ' ', $r['position'] ?? '?')] + $r)),
            'agegender' => $this->ageGender($get('age_gender', ['age', 'gender']), $sub),
            'hours' => $this->hours($get('hour', ['hour']), $sub),
            'weekdays' => $this->weekdays($q, $from, $to, $id, $sub),
            'device' => $this->breakdown('Qurilma', "Reklama qaysi qurilmada ko'rsatilgan. {$sub}", $get('device', ['device'])
                ->map(fn ($r) => ['label' => str_replace('_', ' ', $r['device'] ?? '?')] + $r)),
            'region' => $this->breakdown('Hudud (viloyat)', "Qaysi hududdan lid arzon kelayotgani. {$sub}", $get('region', ['region'])
                ->map(fn ($r) => ['label' => $r['region'] ?? '?'] + $r), limit: 15),
            default => abort(404),
        };
    }

    /**
     * @param  list<string>  $dims
     * @return Collection<int, array<string, mixed>>
     */
    private function live(LiveBreakdown $live, AnalyticsQuery $q, FacebookAdAccount $account, string $kind, array $dims, DateRange $range)
    {
        try {
            return $live->get($account, $kind, $dims, $range->from, $range->to);
        } catch (\Throwable) {
            return $q->breakdown($kind, $dims, $account->id);
        }
    }

    private function overview(FacebookAdAccount $account, AccountReport $reports, AnalyticsQuery $q, DateRange $range, string $tab = 'overview'): View
    {
        $report = $reports->build($account, $range, withRecs: $tab === 'recommendations');
        $latest = AdAlert::max('date');

        return view('panels.'.$tab, [
            'account' => $account,
            'days' => $range->days(),
            'range' => $range,
            'report' => $report,
            't' => $report['t'],
            'p' => $report['p'],
            'freq' => $report['freq'],
            'reach' => $report['reach'],
            'cards' => $tab === 'recommendations' ? app(AdCards::class)->fromAlerts(AdAlert::with('ad.account')->whereHas('ad', fn ($a) => $a->where('facebook_ad_account_id', $account->id))->whereDate('date', Carbon::parse(AdAlert::max('date') ?? today())->toDateString())->get()) : collect(),
            'daily' => $q->daily($range->from, $range->to, $account->id),
            'alerts' => AdAlert::with('ad')
                ->whereHas('ad', fn ($a) => $a->where('facebook_ad_account_id', $account->id))
                ->whereDate('date', Carbon::parse($latest ?? today())->toDateString())
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
                ->get(),
        ]);
    }

    private function campaignAnalysis(Request $request, FacebookAdAccount $account, DateRange $range): View
    {
        $service = app(CampaignAnalysis::class);
        $keywords = collect(explode(',', (string) $request->query('kw')))->map(fn ($k) => trim($k))->filter()->values()->all();
        $data = $keywords ? $service->build($account, $keywords, $range) : null;

        $chart = null;
        if ($data && $data['ageGender']->isNotEmpty()) {
            $ages = $data['ageGender']->pluck('age')->unique()->sort()->values();
            $leads = fn (string $g) => $ages->map(fn ($a) => (int) $data['ageGender']->where('age', $a)->where('gender', $g)->sum('leads'))->all();
            $chart = [
                'type' => 'bar',
                'data' => ['labels' => $ages->all(), 'datasets' => [
                    ['label' => 'Erkak', 'data' => $leads('male'), 'backgroundColor' => '#60a5fa'],
                    ['label' => 'Ayol', 'data' => $leads('female'), 'backgroundColor' => '#f472b6'],
                ]],
                'options' => ['maintainAspectRatio' => false, 'plugins' => ['title' => ['display' => true, 'text' => 'Natijalar soni yosh guruhlari bo\'yicha']]],
            ];
        }

        return view('panels.campaign_analysis', [
            'account' => $account,
            'range' => $range,
            'keywords' => $keywords,
            'families' => $service->families($account, $range),
            'data' => $data,
            'chart' => $chart,
        ]);
    }

    /** "Reklama yoqish": product ranking, and for a typed product a ready plan from its past results. */
    public function launch(Request $request, FacebookAdAccount $account): View
    {
        // a launch needs history, so it looks at 30 days unless another period is picked explicitly
        $range = $request->has('r') ? DateRange::fromRequest($request) : DateRange::lastDays(30);
        $planner = app(LaunchPlanner::class);
        $product = trim((string) $request->query('p'));
        $opts = LaunchPlanner::options($request->query());

        return view('panels.launch', [
            'account' => $account,
            'accounts' => FacebookAdAccount::where('is_tracked', true)->orderBy('name')->get(['id', 'name']),
            'range' => $range,
            'product' => $product,
            'opts' => $opts,
            'products' => $planner->products($account, $range),
            'plan' => $product !== '' ? $planner->plan($account, $product, $range, $opts) : null,
            'canWrite' => $product !== '' && Cache::remember("can-write:{$account->facebook_connection_id}", now()->addMinutes(5), fn () => app(LaunchExecutor::class)->canWrite($account)),
            'result' => session('launch'),
        ]);
    }

    private function video(FacebookAdAccount $account, AnalyticsQuery $q, Carbon $from, Carbon $to, int $id): View
    {
        $ads = $q->ads($from, $to, $id)->filter(fn ($r) => $r['ad']->is_video && $r['m']['video_plays'] > 0)->values();
        $sum = fn (string $k) => $ads->sum(fn ($r) => $r['m'][$k]);
        $plays = max(1, $sum('video_plays'));

        return view('panels.video', [
            'account' => $account,
            'ads' => $ads,
            't' => $q->totals($from, $to, $id),
            'retention' => [
                ['Boshlanish', 100],
                ['25%', round($ads->sum(fn ($r) => $r['m']['p25'] * $r['m']['video_plays'] / 100) / $plays * 100, 1)],
                ['50%', round($ads->sum(fn ($r) => $r['m']['p50'] * $r['m']['video_plays'] / 100) / $plays * 100, 1)],
                ['75%', round($ads->sum(fn ($r) => $r['m']['p75'] * $r['m']['video_plays'] / 100) / $plays * 100, 1)],
                ['95%', round($ads->sum(fn ($r) => $r['m']['p95'] * $r['m']['video_plays'] / 100) / $plays * 100, 1)],
                ['100%', round($ads->sum(fn ($r) => $r['m']['p100'] * $r['m']['video_plays'] / 100) / $plays * 100, 1)],
            ],
        ]);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function breakdown(string $title, string $hint, $rows, int $limit = 0, array $extra = []): View
    {
        $rows = $rows->sortByDesc('spend')->values();

        return view('panels.breakdown', $extra + [
            'title' => $title,
            'hint' => $hint,
            'rows' => $limit ? $rows->take($limit) : $rows,
            'total' => $rows->count(),
            'chart' => null,
        ]);
    }

    private function ageGender($raw, string $sub): View
    {
        $ages = $raw->pluck('age')->unique()->sort()->values();
        $leads = fn (string $g) => $ages->map(fn ($a) => (int) $raw->where('age', $a)->where('gender', $g)->sum('leads'))->all();

        return $this->breakdown(
            'Yosh va jins',
            "Qaysi guruh eng ko'p va eng arzon lid beryapti. {$sub} \"Unknown\": Meta aniqlay olmaganlar.",
            $raw->map(fn ($r) => ['label' => (self::GENDERS[$r['gender']] ?? $r['gender']).', '.$r['age']] + $r),
            extra: ['chart' => [
                'type' => 'bar',
                'data' => ['labels' => $ages->all(), 'datasets' => [
                    ['label' => 'Erkak', 'data' => $leads('male'), 'backgroundColor' => '#60a5fa'],
                    ['label' => 'Ayol', 'data' => $leads('female'), 'backgroundColor' => '#f472b6'],
                ]],
                'options' => ['maintainAspectRatio' => false, 'plugins' => ['title' => ['display' => true, 'text' => 'Lidlar soni yosh guruhlari bo\'yicha']]],
            ]],
        );
    }

    private function hours($hourRows, string $sub): View
    {
        $raw = $hourRows->keyBy('hour');
        $rows = collect(range(0, 23))->map(function ($h) use ($raw) {
            return ['label' => sprintf('%02d:00', $h), 'hour' => $h] + ($raw[$h] ?? AdMetrics::derive([]) + ['hour' => $h]);
        });

        return $this->breakdown('Soatlar bo\'yicha', "Reklama akkaunti vaqti bilan. Ustun: lidlar, chiziq: lid narxi. {$sub}", $rows, extra: [
            'sortByLabel' => true,
            'chart' => [
                'type' => 'bar',
                'data' => ['labels' => $rows->pluck('label')->all(), 'datasets' => [
                    ['type' => 'bar', 'label' => 'Lidlar', 'data' => $rows->pluck('leads')->all(), 'backgroundColor' => '#86efac', 'yAxisID' => 'y'],
                    ['type' => 'line', 'label' => 'Lid narxi', 'data' => $rows->pluck('cpl')->all(), 'borderColor' => '#dc2626', 'backgroundColor' => '#dc2626', 'tension' => .3, 'spanGaps' => true, 'yAxisID' => 'y1'],
                ]],
                'options' => ['maintainAspectRatio' => false, 'scales' => ['y1' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false]]]],
            ],
        ]);
    }

    private function weekdays(AnalyticsQuery $q, Carbon $from, Carbon $to, int $id, string $sub): View
    {
        $rows = $q->weekdays($from, $to, $id);

        return $this->breakdown('Hafta kunlari', "Qaysi kunlarda reklama yaxshi ishlaydi (kamida bir hafta tanlang). {$sub}", $rows, extra: [
            'sortByLabel' => true,
            'chart' => [
                'type' => 'bar',
                'data' => ['labels' => $rows->pluck('label')->all(), 'datasets' => [
                    ['type' => 'bar', 'label' => 'Lidlar', 'data' => $rows->pluck('leads')->all(), 'backgroundColor' => '#a5b4fc', 'yAxisID' => 'y'],
                    ['type' => 'line', 'label' => 'Lid narxi', 'data' => $rows->pluck('cpl')->all(), 'borderColor' => '#dc2626', 'backgroundColor' => '#dc2626', 'tension' => .3, 'yAxisID' => 'y1'],
                ]],
                'options' => ['maintainAspectRatio' => false, 'scales' => ['y1' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false]]]],
            ],
        ]);
    }
}
