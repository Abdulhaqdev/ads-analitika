<?php

namespace App\Http\Controllers;

use App\Models\AdAlert;
use App\Services\AdCards;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AlertsController extends Controller
{
    public function index(AdCards $cards): View
    {
        $latest = AdAlert::max('date');

        $alerts = $latest
            ? AdAlert::with('ad.account')
                ->whereDate('date', Carbon::parse($latest)->toDateString())
                ->whereHas('ad.account', fn ($a) => $a->where('is_tracked', true))
                ->get()
            : collect();

        $groups = $cards->fromAlerts($alerts)
            ->groupBy(fn ($c) => $c['ad']->account->name)
            ->sortByDesc(fn ($g) => $g->where('kind', '!=', 'good')->count());

        return view('alerts', ['groups' => $groups, 'date' => $latest ? Carbon::parse($latest) : null]);
    }
}
