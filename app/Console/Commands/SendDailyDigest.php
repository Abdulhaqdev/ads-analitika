<?php

namespace App\Console\Commands;

use App\Services\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendDailyDigest extends Command
{
    protected $signature = 'analytics:digest';

    protected $description = 'Har bir akkaunt uchun alohida kunlik hisobotni Telegramga yuboradi';

    public function handle(TelegramBot $bot): int
    {
        if (! $bot->enabled()) {
            $this->warn('TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID sozlanmagan.');

            return self::SUCCESS;
        }

        $accounts = $bot->accountsWithSpend();
        $bot->send('📅 <b>Kunlik hisobot — '.Carbon::yesterday()->format('d.m.Y').'</b>'."\n".$accounts->count().' ta faol akkaunt. Har biri alohida xabarda.', $bot->mainKeyboard());

        foreach ($accounts as $account) {
            $bot->send($bot->accountCard($account), $bot->accountButtons($account));
            usleep(600000);
        }

        $this->info($accounts->count().' ta akkaunt hisoboti yuborildi.');

        return self::SUCCESS;
    }
}
