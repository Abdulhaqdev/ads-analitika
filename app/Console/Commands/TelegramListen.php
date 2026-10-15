<?php

namespace App\Console\Commands;

use App\Models\FacebookAdAccount;
use App\Services\AnalyticsQuery;
use App\Services\DateRange;
use App\Services\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class TelegramListen extends Command
{
    protected $signature = 'telegram:listen';

    protected $description = 'Telegram botni tinglaydi: tugmalar, #hashtag va buyruqlarga javob beradi';

    public function handle(TelegramBot $bot, AnalyticsQuery $q): int
    {
        if (! $bot->enabled()) {
            $this->error('TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID sozlanmagan.');

            return self::FAILURE;
        }

        $bot->registerCommands();
        $offset = (int) Cache::get('telegram_offset', 0);
        $this->info('Bot tinglanmoqda… (Ctrl+C bilan to\'xtatiladi)');

        while (true) {
            $response = $bot->api('getUpdates', ['offset' => $offset, 'timeout' => 25, 'allowed_updates' => ['message', 'callback_query']], 35);

            foreach ($response['result'] ?? [] as $update) {
                $offset = $update['update_id'] + 1;
                Cache::forever('telegram_offset', $offset);

                try {
                    $this->handleUpdate($bot, $update);
                } catch (\Throwable $e) {
                    report($e);
                    $bot->send('Xatolik yuz berdi: '.e($e->getMessage()));
                }
            }

            if (! isset($response['result'])) {
                sleep(5);
            }
        }
    }

    /** @param  array<string, mixed>  $update */
    private function handleUpdate(TelegramBot $bot, array $update): void
    {
        $chat = (string) ($update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? '');
        if ($chat !== $bot->chatId()) {
            return;
        }

        if (isset($update['callback_query'])) {
            $cb = $update['callback_query'];
            $bot->api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
            $this->handleCallback($bot, $cb['data'] ?? '');

            return;
        }

        $text = trim($update['message']['text'] ?? '');
        if ($text === '') {
            return;
        }

        match (true) {
            in_array($text, ['/start', '/menu'], true) => $bot->send("👋 <b>Analitik bot</b>\n\nReklama akkauntlaringiz tahlili. Pastdagi tugmalardan foydalaning yoki <b>#akkaunt_nomi</b> deb yozing (# bosganda akkauntlar chiqadi).", $bot->mainKeyboard()),
            in_array($text, ['/akkauntlar', '📊 Akkauntlarim'], true) => $this->accountList($bot),
            in_array($text, ['/reklamalar', '📢 Reklamalar'], true) => $bot->send('📢 Qaysi akkauntning reklamalari?', $bot->picker('ads')),
            in_array($text, ['/xarajatlar', '💰 Xarajatlar'], true) => $bot->send('💰 Qaysi akkauntning xarajatlari?', $bot->picker('spend', true)),
            in_array($text, ['/ogohlantirishlar', '⚠️ Ogohlantirishlar'], true) => $this->alerts($bot),
            in_array($text, ['/hisobot', '📅 Kunlik hisobot'], true) => $this->digest($bot),
            default => $this->search($bot, $text),
        };
    }

    private function accountList(TelegramBot $bot): void
    {
        $tags = $bot->accounts()->map(fn ($a) => $a->hashtag())->implode("\n");
        $bot->send("📊 <b>Akkauntlarim</b>\nBittasini tanlang yoki hashtagni yozing:\n\n".e($tags), $bot->picker('acc'));
    }

    private function alerts(TelegramBot $bot): void
    {
        $sent = 0;
        foreach ($bot->accounts() as $account) {
            if ($card = $bot->alertsCard($account)) {
                $bot->send($card, $bot->accountButtons($account));
                $sent++;
                usleep(400000);
            }
        }
        if ($sent === 0) {
            $bot->send("✅ Bugun ogohlantirish yo'q.");
        }
    }

    private function digest(TelegramBot $bot): void
    {
        $accounts = $bot->accountsWithSpend();
        $bot->send('📅 <b>Kunlik hisobot</b> — '.$accounts->count().' ta akkaunt (har biri alohida xabarda)');
        foreach ($accounts as $account) {
            $bot->send($bot->accountCard($account), $bot->accountButtons($account));
            usleep(500000);
        }
    }

    private function search(TelegramBot $bot, string $text): void
    {
        if ($account = $bot->findAccount($text)) {
            $bot->send($bot->accountCard($account), $bot->accountButtons($account));

            return;
        }

        $bot->send('Akkaunt topilmadi. Quyidagilardan tanlang:', $bot->picker('acc'));
    }

    private function handleCallback(TelegramBot $bot, string $data): void
    {
        if ($data === 'list') {
            $this->accountList($bot);

            return;
        }

        [$action, $id, $arg] = array_pad(explode(':', $data, 3), 3, null);

        if ($id === 'all' && $action === 'spend') {
            foreach ($bot->accountsWithSpend() as $account) {
                $bot->send($bot->spendCard($account), $bot->accountButtons($account));
                usleep(400000);
            }

            return;
        }

        $account = FacebookAdAccount::find((int) $id);
        if (! $account) {
            return;
        }

        $q = app(AnalyticsQuery::class);
        $genders = ['male' => 'Erkak', 'female' => 'Ayol', 'unknown' => "Noma'lum"];
        $buttons = $bot->accountButtons($account);

        match ($action) {
            'acc' => $bot->send($bot->accountCard($account), $buttons),
            'rng' => $bot->send($bot->accountCard($account, 7, DateRange::preset((string) $arg)), $buttons),
            'ads' => $bot->send($bot->adsList($account), $buttons),
            'spend' => $bot->send($bot->spendCard($account), $buttons),
            'plc' => $bot->send($bot->barsCard($account, '📍 Joylashuv', $q->breakdown('placement', ['platform', 'position'], $account->id)
                ->map(fn ($r) => ['label' => ucfirst($r['platform'] ?? '?').' · '.str_replace('_', ' ', $r['position'] ?? '?')] + $r)), $buttons),
            'age' => $bot->send($bot->barsCard($account, '👥 Yosh va jins', $q->breakdown('age_gender', ['age', 'gender'], $account->id)
                ->filter(fn ($r) => strtolower((string) $r['age']) !== 'unknown' && ($r['gender'] ?? '') !== 'unknown')
                ->map(fn ($r) => ['label' => ($genders[$r['gender']] ?? $r['gender']).', '.$r['age']] + $r)), $buttons),
            'hrs' => $bot->send($bot->barsCard($account, '🕐 Soatlar', $q->breakdown('hour', ['hour'], $account->id)
                ->map(fn ($r) => ['label' => sprintf('%02d:00', $r['hour'])] + $r), 24), $buttons),
            'reg' => $bot->send($bot->barsCard($account, '🗺 Hudud', $q->breakdown('region', ['region'], $account->id)
                ->map(fn ($r) => ['label' => $r['region'] ?? '?'] + $r)), $buttons),
            default => null,
        };
    }
}
