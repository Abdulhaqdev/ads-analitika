<?php

namespace App\Http\Controllers;

use App\Models\FacebookAdAccount;
use App\Services\DateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public const SECTIONS = [
        'Tahlil' => [
            'overview' => ['Umumiy ko\'rinish', 'Asosiy metrikalar'],
            'statuses' => ['Holatlar', "Har bir ko'rsatkich yaxshimi yoki yomonmi"],
            'reasons' => ['Sabablar', 'Nima uchun natija o\'zgardi'],
            'recommendations' => ['Tavsiyalar', 'Nimani tuzatish va kuchaytirish kerak'],
        ],
        'Reklamalar' => [
            'campaigns' => ['Kampaniyalar', null],
            'ads' => ['Reklamalar', null],
            'video' => ['Video', 'Hook, hold, ushlab turish'],
            'campaign-analysis' => ['Kampaniya tahlili', 'Istalgan kampaniya nomi bo\'yicha: yosh, jins, auditoriya, videolar'],
        ],
        'Auditoriya va joy' => [
            'placement' => ['Joylashuv', null],
            'agegender' => ['Yosh va jins', null],
            'hours' => ['Soatlar', null],
            'weekdays' => ['Hafta kunlari', null],
            'device' => ['Qurilma', null],
            'region' => ['Hudud', null],
        ],
    ];

    public function show(Request $request, FacebookAdAccount $account, PanelController $panel, ?string $section = null): View|RedirectResponse
    {
        abort_unless($account->is_tracked, 404);

        // "Reklama yoqish" moved to its own page in the main menu; old links still land there
        if ($section === 'launch') {
            return redirect()->route('launch', ['a' => $account->id] + $request->query());
        }

        $section ??= 'overview';
        $flat = collect(self::SECTIONS)->collapse();
        abort_unless($flat->has($section), 404);

        $content = app()->call([$panel, 'show'], ['request' => $request, 'account' => $account, 'tab' => $section])->render();

        return view('account', [
            'account' => $account,
            'range' => DateRange::fromRequest($request),
            'section' => $section,
            'sections' => self::SECTIONS,
            'title' => $flat[$section][0],
            'content' => $content,
        ]);
    }
}
