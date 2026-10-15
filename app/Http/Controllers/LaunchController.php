<?php

namespace App\Http\Controllers;

use App\Models\FacebookAdAccount;
use App\Services\DateRange;
use App\Services\LaunchExecutor;
use App\Services\LaunchPlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LaunchController extends Controller
{
    /** Standalone "Reklama yoqish" page: pick the account here instead of opening it first. */
    public function index(Request $request, PanelController $panel): View
    {
        $accounts = FacebookAdAccount::where('is_tracked', true)->orderBy('name');
        $account = $request->filled('a') ? (clone $accounts)->find($request->integer('a')) : null;
        $account ??= (clone $accounts)->find(session('launch_account')) ?? $accounts->first();

        if (! $account) {
            return view('launch', [
                'account' => null,
                'content' => view('panels.launch-empty')->render(),
            ]);
        }

        session(['launch_account' => $account->id]);

        return view('launch', ['account' => $account, 'content' => $panel->launch($request, $account)->render()]);
    }

    /** Builds the plan again from the same inputs and prepares it in Ads Manager, paused. */
    public function store(Request $request, FacebookAdAccount $account, LaunchPlanner $planner, LaunchExecutor $executor): RedirectResponse
    {
        abort_unless($account->is_tracked, 404);

        // A campaign with several adsets and ads takes a dozen Graph calls, well past PHP's 30 second default.
        set_time_limit(600);

        $data = $request->validate([
            'p' => ['required', 'string', 'max:100'],
            'adsets' => ['nullable', 'integer', 'min:1', 'max:8'],
            'videos' => ['nullable', 'integer', 'min:1', 'max:8'],
            'budget' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'structure' => ['nullable', 'in:cbo,abo'],
            'split' => ['nullable', 'boolean'],
            'link' => ['nullable', 'url', 'max:500'],
            'dest' => ['nullable', 'in:site,form'],
            'form' => ['nullable', 'regex:/^\d{6,20}$/'],
            'pick_adsets' => ['nullable', 'array', 'max:20'],
            'pick_adsets.*' => ['regex:/^\d{5,25}$/'],
            'pick_videos' => ['nullable', 'array', 'max:20'],
            'pick_videos.*' => ['regex:/^\d{5,25}$/'],
            'r' => ['nullable', 'string'],
        ]);

        // the period arrives in the POST body, but DateRange reads the query string: without this the
        // plan silently fell back to 7 days and lost groups and videos picked on the 30-day page
        $request->query->add($request->only('r', 'from', 'to'));
        $range = $request->filled('r') ? DateRange::fromRequest($request) : DateRange::lastDays(30);
        $opts = LaunchPlanner::options($data);
        $plan = $planner->plan($account, $data['p'], $range, $opts);
        $back = redirect()->route('launch', ['a' => $account->id] + $request->only('p', 'adsets', 'videos', 'budget', 'structure', 'split', 'link', 'dest', 'form', 'pick_adsets', 'pick_videos', 'r'));

        if (! $plan) {
            return $back->with('launch', ['ok' => false, 'error' => 'Reja tuzilmadi: bu nomli kampaniya topilmadi.']);
        }

        // what was ticked on the page must still be there; otherwise Graph answered only partly (rate limit)
        $lost = collect([
            'guruh' => [count($opts['pick_adsets'] ?? []), count($plan['adsets'])],
            'video' => [count($opts['pick_videos'] ?? []), count($plan['creatives'])],
        ])->filter(fn ($n) => $n[0] > 0 && $n[1] < $n[0]);
        if ($lost->isNotEmpty()) {
            return $back->with('launch', ['ok' => false, 'error' => 'Facebook hozir hamma ma\'lumotni bermadi ('
                .$lost->map(fn ($n, $k) => "{$n[1]}/{$n[0]} {$k}")->implode(', ').'), hech narsa yaratilmadi. 1–2 daqiqadan keyin qayta bosing.']);
        }

        $name = ucfirst($plan['product']).' / '.strtoupper($opts['structure']).' '.rtrim(rtrim(number_format($plan['budget'], 2, '.', ''), '0'), '.').'$ / '.now()->format('d.m H:i');

        return $back->with('launch', $executor->prepare($account, $plan, $name) + ['name' => $name]);
    }
}
