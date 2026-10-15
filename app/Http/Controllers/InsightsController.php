<?php

namespace App\Http\Controllers;

use App\Models\FacebookAdAccount;
use App\Services\AnalyticsQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class InsightsController extends Controller
{
    public function index(Request $request, AnalyticsQuery $q): View
    {
        $accountId = $request->integer('account') ?: null;
        $to = Carbon::yesterday();
        $from = $to->copy()->subDays(29);

        $ageGender = $q->breakdown('age_gender', ['age', 'gender'], $accountId);

        return view('insights', [
            'accountId' => $accountId,
            'accounts' => FacebookAdAccount::where('is_tracked', true)->orderBy('name')->get(),
            'ageGender' => $ageGender,
            'ages' => $q->breakdown('age_gender', ['age'], $accountId)->sortBy('age')->values(),
            'genders' => $q->breakdown('age_gender', ['gender'], $accountId),
            'hours' => $q->breakdown('hour', ['hour'], $accountId)->sortBy('hour')->values(),
            'weekdays' => $q->weekdays($from, $to, $accountId),
            'placements' => $q->breakdown('placement', ['platform', 'position'], $accountId)->sortByDesc('spend')->values(),
            'devices' => $q->breakdown('device', ['device'], $accountId)->sortByDesc('spend')->values(),
            'regions' => $q->breakdown('region', ['region'], $accountId)->sortByDesc('spend')->values(),
        ]);
    }
}
