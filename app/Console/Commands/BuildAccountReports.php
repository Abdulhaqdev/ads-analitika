<?php

namespace App\Console\Commands;

use App\Models\AccountReportSnapshot;
use App\Models\FacebookAdAccount;
use App\Services\AccountReport;
use App\Services\DateRange;
use Illuminate\Console\Command;

class BuildAccountReports extends Command
{
    protected $signature = 'analytics:reports {--days=7}';

    protected $description = 'Faol akkauntlar uchun xato va tavsiyalar hisobotini tayyorlab saqlaydi';

    public function handle(AccountReport $reports): int
    {
        $days = (int) $this->option('days');

        foreach (FacebookAdAccount::where('is_tracked', true)->with('connection')->get() as $account) {
            try {
                $r = $reports->build($account, DateRange::lastDays($days));

                AccountReportSnapshot::updateOrCreate(
                    ['facebook_ad_account_id' => $account->id, 'days' => $days],
                    ['payload' => [
                        't' => $r['t'],
                        'p' => $r['p'],
                        'freq' => $r['freq'],
                        'statuses' => $r['statuses'],
                        'reasons' => $r['reasons'],
                        'recs' => $r['recs'],
                        'from' => $r['from']->toDateString(),
                        'to' => $r['to']->toDateString(),
                    ], 'computed_at' => now()],
                );
                $this->line("✓ {$account->name}");
            } catch (\Throwable $e) {
                report($e);
                $this->error("✗ {$account->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
