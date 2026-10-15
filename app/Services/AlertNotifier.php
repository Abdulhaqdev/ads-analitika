<?php

namespace App\Services;

use App\Models\AdAlert;

class AlertNotifier
{
    public function sendPending(): int
    {
        $token = config('analytics.telegram_token');
        $chat = config('analytics.telegram_chat_id');

        if (! $token || ! $chat) {
            return 0;
        }

        $alerts = AdAlert::with('ad.account')
            ->whereNull('notified_at')
            ->whereIn('severity', ['critical', 'warning'])
            ->whereDate('date', today())
            ->get();

        if ($alerts->isEmpty()) {
            return 0;
        }

        $bot = app(TelegramBot::class);
        $cards = app(AdCards::class)->fromAlerts($alerts)->where('kind', '!=', 'good')->groupBy(fn ($c) => $c['ad']->facebook_ad_account_id);

        foreach ($cards as $items) {
            $account = $items->first()['ad']->account;
            $bot->send($bot->adLines($account, $items->take(8)), $bot->accountButtons($account));
            usleep(600000);
        }

        AdAlert::whereIn('id', $alerts->pluck('id'))->update(['notified_at' => now()]);

        return $alerts->count();
    }
}
