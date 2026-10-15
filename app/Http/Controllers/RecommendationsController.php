<?php

namespace App\Http\Controllers;

use App\Models\AccountReportSnapshot;
use App\Models\AdAlert;
use App\Services\AdCards;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class RecommendationsController extends Controller
{
    public function index(Request $request, AdCards $adCards): View
    {
        $filter = in_array($request->query('f'), ['bad', 'good'], true) ? $request->query('f') : 'all';

        $snapshots = AccountReportSnapshot::with('account')
            ->whereHas('account', fn ($a) => $a->where('is_tracked', true))
            ->where('days', 7)
            ->get()
            ->sortByDesc(fn ($s) => $s->redCount() * 1000 + ($s->payload['t']['spend'] ?? 0))
            ->values();

        $latest = AdAlert::max('date');
        $cards = $latest
            ? $adCards->fromAlerts(AdAlert::with('ad.account')->whereDate('date', Carbon::parse($latest)->toDateString())->whereHas('ad.account', fn ($a) => $a->where('is_tracked', true))->get())
                ->groupBy(fn ($c) => $c['ad']->facebook_ad_account_id)
            : collect();

        return view('recommendations', [
            'cards' => $cards,
            'snapshots' => $snapshots,
            'filter' => $filter,
            'badTotal' => $snapshots->sum(fn ($s) => $s->redCount()),
            'goodTotal' => $snapshots->sum(fn ($s) => collect($s->payload['recs'] ?? [])->where('level', 'good')->count()),
        ]);
    }
}
