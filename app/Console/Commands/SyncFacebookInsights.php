<?php

namespace App\Console\Commands;

use App\Models\FacebookAdAccount;
use App\Services\AdAnalyzer;
use App\Services\AlertNotifier;
use App\Services\InsightsSync;
use Illuminate\Console\Command;

class SyncFacebookInsights extends Command
{
    protected $signature = 'facebook:sync {--days=30 : Necha kunlik ma\'lumot} {--account= : Faqat shu akkaunt (act_ raqamisiz ID)} {--no-alerts : Ogohlantirishlarni hisoblamaslik} {--daily-only : Faqat kunlik statistika (tez)}';

    protected $description = 'Kuzatilayotgan reklama akkauntlaridan statistikani yuklaydi va ogohlantirishlarni hisoblaydi';

    public function handle(InsightsSync $sync, AdAnalyzer $analyzer, AlertNotifier $notifier): int
    {
        ini_set('memory_limit', '1536M');

        $accounts = FacebookAdAccount::with('connection')
            ->where('is_tracked', true)
            ->when($this->option('account'), fn ($q, $id) => $q->where('account_id', $id))
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('Kuzatiladigan akkaunt yo\'q. Panelda akkauntni "Kuzatish" ga qo\'ying.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->line("→ {$account->name} (act_{$account->account_id})");

            try {
                $sync->syncAccount($account, (int) $this->option('days'), (bool) $this->option('daily-only'));
                $this->info('  tayyor: '.$account->ads()->count().' ta reklama');
            } catch (\Throwable $e) {
                report($e);
                $this->error('  xato: '.$e->getMessage());
            }
        }

        if (! $this->option('no-alerts')) {
            $this->info('Ogohlantirishlar: '.$analyzer->run().' ta');
            $sent = $notifier->sendPending();
            if ($sent) {
                $this->info("Telegramga yuborildi: {$sent} ta");
            }
        }

        return self::SUCCESS;
    }
}
