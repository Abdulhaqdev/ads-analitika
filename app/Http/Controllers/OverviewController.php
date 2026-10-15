<?php

namespace App\Http\Controllers;

use App\Models\FacebookAdAccount;
use App\Services\AnalyticsQuery;
use App\Services\DateRange;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function index(Request $request, AnalyticsQuery $q): View
    {
        $range = DateRange::fromRequest($request);

        return view('overview', [
            'range' => $range,
            'cards' => $q->accountCards($range->from, $range->to, $range->prevFrom(), $range->prevTo()),
            'totals' => $q->totals($range->from, $range->to, null),
            'prev' => $q->totals($range->prevFrom(), $range->prevTo(), null),
            'inactive' => FacebookAdAccount::where('is_tracked', false)->whereNotIn('account_status', [2, 101])->orderByDesc('spend_30d')->orderBy('name')->get(),
            'open' => $request->integer('open') ?: null,
        ]);
    }
}
