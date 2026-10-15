<?php

namespace App\Console\Commands;

use App\Models\FacebookAdAccount;
use App\Services\FacebookGraph;
use Illuminate\Console\Command;

class DiscoverActiveAccounts extends Command
{
    protected $signature = 'facebook:discover';

    protected $description = 'Faol akkauntlarni aniqlaydi: oxirgi 30 kunda sarfi bor yoki hozir faol reklamasi bor akkauntlarni kuzatishga qo\'shadi';

    public function handle(FacebookGraph $graph): int
    {
        $added = $removed = 0;

        foreach (FacebookAdAccount::with('connection')->get() as $account) {
            try {
                $token = $account->connection->access_token;
                $act = 'act_'.$account->account_id;

                $spend = (float) ($graph->get("{$act}/insights", ['date_preset' => 'last_30d', 'fields' => 'spend'], $token)['data'][0]['spend'] ?? 0);
                $active = count($graph->get("{$act}/ads", [
                    'fields' => 'id',
                    'limit' => 25,
                    'filtering' => json_encode([['field' => 'effective_status', 'operator' => 'IN', 'value' => ['ACTIVE']]]),
                ], $token)['data'] ?? []);
            } catch (\Throwable $e) {
                $this->warn("  {$account->name}: {$e->getMessage()}");

                continue;
            }

            $isActive = $spend > 0 || $active > 0;
            $shouldTrack = $isActive || $account->pinned;

            if ($shouldTrack && ! $account->is_tracked) {
                $added++;
                $this->info("+ {$account->name}: kuzatishga qo'shildi (sarf {$spend}, faol reklama {$active})");
            } elseif (! $shouldTrack && $account->is_tracked) {
                $removed++;
                $this->line("- {$account->name}: faol emas, kuzatuvdan chiqarildi");
            }

            $account->update([
                'is_tracked' => $shouldTrack,
                'spend_30d' => $spend,
                'active_ads' => $active,
                'activity_checked_at' => now(),
            ]);
        }

        $this->info("Qo'shildi: {$added}, chiqarildi: {$removed}, kuzatuvda: ".FacebookAdAccount::where('is_tracked', true)->count());

        return self::SUCCESS;
    }
}
