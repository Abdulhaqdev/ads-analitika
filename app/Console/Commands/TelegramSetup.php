<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TelegramSetup extends Command
{
    protected $signature = 'telegram:setup';

    protected $description = 'Botga yozilgan xabardan chat ID ni topib, .env ga yozadi va sinov xabarini yuboradi';

    public function handle(): int
    {
        $token = config('analytics.telegram_token');

        if (! $token) {
            $this->error('.env da TELEGRAM_BOT_TOKEN yo\'q.');

            return self::FAILURE;
        }

        $me = Http::get("https://api.telegram.org/bot{$token}/getMe")->json();
        if (! ($me['ok'] ?? false)) {
            $this->error('Token noto\'g\'ri: '.($me['description'] ?? 'noma\'lum xato'));

            return self::FAILURE;
        }

        $updates = Http::get("https://api.telegram.org/bot{$token}/getUpdates")->json('result', []);
        $chat = collect($updates)->reverse()->map(fn ($u) => $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? null)->filter()->first();

        if (! $chat) {
            $this->warn("Botga hali xabar yozilmagan. Telegramda @{$me['result']['username']} ni oching, /start bosing va qayta ishga tushiring.");

            return self::FAILURE;
        }

        $env = base_path('.env');
        $contents = file_get_contents($env);
        $contents = preg_match('/^TELEGRAM_CHAT_ID=.*$/m', $contents)
            ? preg_replace('/^TELEGRAM_CHAT_ID=.*$/m', 'TELEGRAM_CHAT_ID='.$chat['id'], $contents)
            : $contents."\nTELEGRAM_CHAT_ID={$chat['id']}\n";
        file_put_contents($env, $contents);

        Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chat['id'],
            'text' => '✅ Analitik agentim ulandi. Bu yerga reklama ogohlantirishlari va kunlik hisobot keladi.',
        ]);

        $this->info("Ulandi: chat {$chat['id']} (".($chat['first_name'] ?? $chat['title'] ?? '').')');

        return self::SUCCESS;
    }
}
