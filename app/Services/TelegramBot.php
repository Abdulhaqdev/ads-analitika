<?php

namespace App\Services;

use App\Models\AdAlert;
use App\Models\FacebookAdAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBot
{
    private const EMOJI = ['good' => '🟢', 'warn' => '🟡', 'bad' => '🔴', 'idle' => '⚪'];

    public function __construct(private AccountReport $reports, private AnalyticsQuery $q) {}

    public function enabled(): bool
    {
        return filled(config('analytics.telegram_token')) && filled(config('analytics.telegram_chat_id'));
    }

    public function chatId(): string
    {
        return (string) config('analytics.telegram_chat_id');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function api(string $method, array $params = [], int $timeout = 15): array
    {
        try {
            return Http::timeout($timeout)->post('https://api.telegram.org/bot'.config('analytics.telegram_token').'/'.$method, $params)->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning("Telegram {$method} failed: ".$e->getMessage());

            return [];
        }
    }

    /** @param  array<string, mixed>|null  $markup */
    public function send(string $text, ?array $markup = null, ?string $chatId = null): void
    {
        $this->api('sendMessage', array_filter([
            'chat_id' => $chatId ?? $this->chatId(),
            'text' => mb_substr($text, 0, 4000),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $markup,
        ]));
    }

    /** @return array<string, mixed> */
    public function mainKeyboard(): array
    {
        return ['keyboard' => [
            [['text' => '📊 Akkauntlarim'], ['text' => '📢 Reklamalar']],
            [['text' => '💰 Xarajatlar'], ['text' => '⚠️ Ogohlantirishlar']],
            [['text' => '📅 Kunlik hisobot']],
        ], 'resize_keyboard' => true, 'is_persistent' => true];
    }

    public function registerCommands(): void
    {
        $this->api('setMyCommands', ['commands' => [
            ['command' => 'menu', 'description' => 'Asosiy menyu'],
            ['command' => 'akkauntlar', 'description' => 'Reklama akkauntlarim'],
            ['command' => 'reklamalar', 'description' => 'Faol reklamalar'],
            ['command' => 'xarajatlar', 'description' => 'Xarajatlar'],
            ['command' => 'ogohlantirishlar', 'description' => 'Bugungi ogohlantirishlar'],
            ['command' => 'hisobot', 'description' => 'Kunlik hisobot'],
        ]]);
    }

    /** @return Collection<int, FacebookAdAccount> */
    public function accounts(): Collection
    {
        return FacebookAdAccount::where('is_tracked', true)->orderBy('name')->get();
    }

    /** @return array<string, mixed> account picker; $prefix is the callback action */
    public function picker(string $prefix, bool $withAll = false): array
    {
        $buttons = $this->accounts()->map(fn ($a) => ['text' => $a->name, 'callback_data' => "{$prefix}:{$a->id}"])->all();
        $rows = array_chunk($buttons, 2);

        if ($withAll) {
            array_unshift($rows, [['text' => '🌐 Hammasi (alohida xabarlar)', 'callback_data' => "{$prefix}:all"]]);
        }

        return ['inline_keyboard' => $rows];
    }

    /** @return array<string, mixed> */
    public function accountButtons(FacebookAdAccount $account): array
    {
        $id = $account->id;

        return ['inline_keyboard' => [
            [['text' => '📢 Reklamalar', 'callback_data' => "ads:{$id}"], ['text' => '💰 Xarajat', 'callback_data' => "spend:{$id}"]],
            [['text' => '📍 Joylashuv', 'callback_data' => "plc:{$id}"], ['text' => '👥 Yosh/jins', 'callback_data' => "age:{$id}"]],
            [['text' => '🕐 Soatlar', 'callback_data' => "hrs:{$id}"], ['text' => '🗺 Hudud', 'callback_data' => "reg:{$id}"]],
            [['text' => 'Bugun', 'callback_data' => "rng:{$id}:today"], ['text' => 'Kecha', 'callback_data' => "rng:{$id}:yesterday"], ['text' => '7 kun', 'callback_data' => "rng:{$id}:7"], ['text' => '30 kun', 'callback_data' => "rng:{$id}:30"]],
            [['text' => '🔗 Ads Manager', 'url' => $account->adsManagerUrl()], ['text' => '⬅ Akkauntlar', 'callback_data' => 'list']],
        ]];
    }

    public function findAccount(string $text): ?FacebookAdAccount
    {
        $needle = mb_strtolower(ltrim(trim($text), '#'));
        if ($needle === '') {
            return null;
        }
        $accounts = $this->accounts();

        return $accounts->first(fn ($a) => ltrim($a->hashtag(), '#') === $needle)
            ?? $accounts->first(fn ($a) => mb_strtolower($a->name) === $needle)
            ?? $accounts->first(fn ($a) => str_contains(ltrim($a->hashtag(), '#'), $needle) || str_contains(mb_strtolower($a->name), $needle));
    }

    // ---------- formatting ----------

    public function accountCard(FacebookAdAccount $account, int $days = 7, ?DateRange $range = null): string
    {
        $range ??= DateRange::lastDays($days);
        $days = $range->days();
        $r = $this->reports->build($account, $range);
        $t = $r['t'];
        $p = $r['p'];
        $cur = $account->currency === 'USD' ? '$' : '';
        $arrow = function (?float $now, ?float $before, bool $lowerBetter = true): string {
            if ($now === null || $before === null || $before <= 0 || abs($now / $before - 1) < 0.05) {
                return '';
            }

            return (($now < $before) === $lowerBetter) ? ' 🟢' : ' 🔴';
        };

        $lines = [
            '📌 <b>'.e($account->hashtag()).'</b>',
            '<b>'.e($account->name).'</b> · '.e($range->label()),
        ];

        if ($t['spend'] <= 0) {
            $lines[] = "\nBu davrda sarf yo'q yoki ma'lumot yuklanmagan.";

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = '💰 Sarf <b>'.$cur.number_format($t['spend'], 2).'</b>';
        foreach ($t['types'] ?: [$t['rtype'] => ['results' => $t['leads'], 'cost' => $t['cpl']]] as $tk => $tv) {
            $main = $tk === $t['rtype'];
            $lines[] = (ResultType::ICON[$tk] ?? '•').' '.e(ResultType::SHORT[$tk] ?? '').' <b>'.number_format($tv['results']).'</b> · narxi <b>'.($tv['cost'] ? $cur.$tv['cost'] : '—').'</b>'.($main ? $arrow($t['cpl'], $p['cpl']) : '');
        }
        $lines[] = 'CPM '.($t['cpm'] ? $cur.$t['cpm'] : '—').$arrow($t['cpm'], $p['cpm']).' · CTR '.($t['ctr'] ?? '—').'%'.$arrow($t['ctr'], $p['ctr'], false);
        if ($t['rtype'] === 'leads') {
            $lines[] = 'Landing/klik '.($t['lpv_rate'] ?? '—').'% · Lid rate '.($t['lead_rate'] ?? '—').'%'.$arrow($t['lead_rate'], $p['lead_rate'], false);
        }
        $lines[] = '🎬 Hook '.($t['hook'] ?? '—').'%'.$arrow($t['hook'], $p['hook'], false).' · Hold '.($t['hold'] ?? '—').'%'.$arrow($t['hold'], $p['hold'], false).' · Chastota '.($r['freq'] ?? '—');

        $lines[] = "\n<b>Holatlar</b>";
        foreach ($r['statuses'] as $s) {
            if ($s['level'] !== 'idle') {
                $lines[] = self::EMOJI[$s['level']].' '.e($s['label']).': <b>'.e($s['value']).'</b> <i>('.e($s['norm']).')</i>';
            }
        }

        $lines[] = "\n<b>Sabablar</b>";
        foreach (array_slice($r['reasons'], 0, 6) as $x) {
            $lines[] = ['bad' => '🔴', 'good' => '🟢', 'idle' => '▫️'][$x['level']].' '.e($x['text']);
        }

        $lines[] = "\n<b>Tavsiyalar</b>";
        foreach (array_slice($r['recs'], 0, 7) as $x) {
            $lines[] = ($x['level'] === 'good' ? '🟢' : '🔴').' <b>'.e($x['title']).'</b>';
            $lines[] = '   '.e(mb_substr($x['detail'], 0, 220));
        }

        return implode("\n", $lines);
    }

    public function adsList(FacebookAdAccount $account, int $days = 7): string
    {
        $to = Carbon::yesterday();
        $from = $to->copy()->subDays($days - 1);
        $ads = $this->q->ads($from, $to, $account->id);
        $active = $ads->filter(fn ($r) => $r['ad']->isActive())->take(12);
        $rows = $active->isNotEmpty() ? $active : $ads->take(12);

        $lines = ['📌 <b>'.e($account->hashtag()).'</b> · 📢 Reklamalar ('.($active->isNotEmpty() ? 'faol' : 'sarfi bor').', '.$days.' kun)', ''];
        if ($rows->isEmpty()) {
            return implode("\n", $lines)."Bu davrda sarf yo'q.";
        }

        foreach ($rows as $row) {
            $m = $row['m'];
            $emoji = ['good' => '🟢', 'ok' => '⚪', 'bad' => '🔴', 'idle' => '⚪'][$row['verdict']['level']];
            $extra = array_filter([
                $m['hook'] !== null ? "Hook {$m['hook']}%" : null,
                $m['hold'] !== null ? "Hold {$m['hold']}%" : null,
                $row['ad']->frequency_7d ? 'Chast. '.$row['ad']->frequency_7d : null,
            ]);
            $lines[] = "{$emoji} <b>".e($row['ad']->name).'</b> — '.e($row['verdict']['label']);
            $lines[] = '   Sarf '.number_format($m['spend'], 2)." · Lid {$m['leads']} · CPL ".($m['cpl'] ?? '—').' · CTR '.($m['ctr'] ?? '—').'%'.($extra ? ' · '.implode(' · ', $extra) : '');
        }

        return implode("\n", $lines);
    }

    public function spendCard(FacebookAdAccount $account): string
    {
        $today = Carbon::yesterday();
        $lines = ['📌 <b>'.e($account->hashtag()).'</b> · 💰 Xarajatlar', ''];

        foreach ([1 => 'Kecha', 3 => '3 kun', 7 => '7 kun', 14 => '14 kun', 30 => '30 kun'] as $d => $label) {
            $t = $this->q->totals($today->copy()->subDays($d - 1), $today, $account->id);
            $lines[] = "<b>{$label}</b>: sarf ".number_format($t['spend'], 2)." · lid {$t['leads']} · CPL ".($t['cpl'] ?? '—').($d > 1 ? ' · kuniga '.number_format($t['spend'] / $d, 2) : '');
        }

        return implode("\n", $lines);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    public function barsCard(FacebookAdAccount $account, string $title, Collection $rows, int $limit = 12): string
    {
        $rows = $rows->filter(fn ($r) => $r['spend'] > 0)->sortByDesc('spend')->take($limit);
        $lines = ['📌 <b>'.e($account->hashtag())."</b> · {$title} (30 kun)", ''];

        if ($rows->isEmpty()) {
            return implode("\n", $lines)."Ma'lumot yo'q.";
        }

        $elig = $rows->filter(fn ($r) => $r['cpl'] !== null && $r['leads'] >= 3)->sortBy('cpl')->values();
        $median = $elig->isNotEmpty() ? $elig->get(intdiv($elig->count(), 2))['cpl'] : null;
        $max = max(1, (int) $rows->max('leads'));

        foreach ($rows as $r) {
            $emoji = ($median && $r['cpl'] !== null && $r['leads'] >= 3) ? ($r['cpl'] <= $median * 0.85 ? '🟢' : ($r['cpl'] >= $median * 1.2 ? '🔴' : '⚪')) : '⚪';
            $bar = str_repeat('▓', max(1, (int) round($r['leads'] / $max * 8)));
            $lines[] = "{$emoji} ".e($r['label']).": {$bar} <b>{$r['leads']}</b> lid · CPL ".($r['cpl'] ?? '—').' · '.number_format($r['spend'], 0);
        }

        return implode("\n", $lines);
    }

    public function alertsCard(FacebookAdAccount $account): ?string
    {
        $latest = AdAlert::max('date');
        if (! $latest) {
            return null;
        }

        $alerts = AdAlert::with('ad.account')
            ->whereHas('ad', fn ($a) => $a->where('facebook_ad_account_id', $account->id))
            ->whereDate('date', Carbon::parse($latest)->toDateString())
            ->get();

        $cards = app(AdCards::class)->fromAlerts($alerts)->where('kind', '!=', 'good')->take(8);

        return $cards->isEmpty() ? null : $this->adLines($account, $cards);
    }

    /** @param  Collection<int, array<string, mixed>>  $cards */
    public function adLines(FacebookAdAccount $account, Collection $cards): string
    {
        $lines = ['📌 <b>'.e($account->hashtag()).'</b> · ⚠️ Qimmatlashgan reklamalar', ''];

        foreach ($cards as $c) {
            $icon = ['off' => '⛔', 'expensive' => '🔴', 'tired' => '🟡', 'good' => '🟢'][$c['kind']];
            $price = $c['now'] ? '$'.number_format($c['now'], 2).($c['before'] ? ' (oldin $'.number_format($c['before'], 2).')' : '') : '';
            $change = $c['change'] !== null ? ($c['change'] > 0 ? ' ▲' : ' ▼').abs($c['change']).'%' : '';
            $lines[] = "{$icon} <b>".e($c['ad']->name).'</b> '.e($price.$change);
            $lines[] = '   '.e($c['cause']);
            $lines[] = '   ➡️ <b>'.e($c['action']).'</b>';
        }

        return implode("\n", $lines);
    }

    /** Active accounts that spent money yesterday. */
    public function accountsWithSpend(): Collection
    {
        $day = Carbon::yesterday();

        return $this->accounts()->filter(fn ($a) => $this->q->totals($day->copy()->subDays(6), $day, $a->id)['spend'] > 0)->values();
    }
}
