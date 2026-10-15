<?php

namespace App\Console\Commands;

use App\Models\AdGuard;
use App\Services\AdGuardian;
use Illuminate\Console\Command;

class GuardAds extends Command
{
    protected $signature = 'ads:guard {--dry : Faqat ko\'rsatish, o\'chirmaslik}';

    protected $description = 'Nazoratdagi kampaniyalarda lid narxi chegaradan oshgan reklamalarni o\'chiradi';

    public function handle(AdGuardian $guardian): int
    {
        foreach (AdGuard::with('account.connection')->where('active', true)->get() as $guard) {
            try {
                $paused = $guardian->run($guard, (bool) $this->option('dry'));
                $budget = $guardian->scale($guard->fresh(), (bool) $this->option('dry'));
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$guard->campaign_name}: {$e->getMessage()}");

                continue;
            }

            foreach ($paused as $p) {
                $this->line(($this->option('dry') ? 'o\'chiriladi' : 'o\'chirildi').": {$guard->campaign_name} / {$p['adset_name']} / {$p['ad_name']} — {$p['reason']}");
            }
            if ($budget) {
                $this->line("byudjet: {$guard->campaign_name} \${$budget['from']} → \${$budget['to']} — {$budget['reason']}");
            }
        }

        return self::SUCCESS;
    }
}
