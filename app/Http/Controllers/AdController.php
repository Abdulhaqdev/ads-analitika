<?php

namespace App\Http\Controllers;

use App\Models\Ad;
use App\Services\AnalyticsQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AdController extends Controller
{
    public function show(Request $request, Ad $ad, AnalyticsQuery $q): View
    {
        $days = in_array((int) $request->query('days'), [7, 14, 30], true) ? (int) $request->query('days') : 14;
        $to = Carbon::yesterday();
        $from = $to->copy()->subDays($days - 1);
        $analyzer = $q->analyzer();

        $recentDays = config('analytics.recent_days');
        $recentFrom = $to->copy()->subDays($recentDays - 1);
        $baseTo = $recentFrom->copy()->subDay();
        $baseFrom = $baseTo->copy()->subDays(config('analytics.baseline_days') - 1);
        $recent = $analyzer->window($ad, $recentFrom, $to);
        $base = $analyzer->window($ad, $baseFrom, $baseTo);

        $ad->load('account');
        $total = $analyzer->window($ad, $from, $to);

        return view('ad', [
            'ad' => $ad,
            'days' => $days,
            'daily' => $q->daily($from, $to, null, $ad->id),
            'adWindow' => null,
            'total' => $total,
            'recent' => $recent,
            'base' => $base,
            'reasons' => $analyzer->reasons($recent, $base, $ad),
            'recommendation' => $analyzer->recommend($recent, $base, $ad),
            'ageGender' => $q->breakdown('age_gender', ['age', 'gender'], null, $ad->id),
            'hours' => $q->breakdown('hour', ['hour'], null, $ad->id)->sortBy('hour')->values(),
            'placements' => $q->breakdown('placement', ['platform', 'position'], null, $ad->id),
            'devices' => $q->breakdown('device', ['device'], null, $ad->id),
            'regions' => $q->breakdown('region', ['region'], null, $ad->id),
            'alerts' => $ad->alerts()->latest('date')->limit(10)->get(),
        ]);
    }
}
