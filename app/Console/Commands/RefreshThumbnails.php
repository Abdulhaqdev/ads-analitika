<?php

namespace App\Console\Commands;

use App\Models\Ad;
use App\Services\FacebookGraph;
use Illuminate\Console\Command;

class RefreshThumbnails extends Command
{
    protected $signature = 'facebook:thumbs {--days=14}';

    protected $description = 'Oxirgi kunlarda sarfi bor reklamalarning katta rasmlarini (480px) yangilaydi';

    public function handle(FacebookGraph $graph): int
    {
        $updated = 0;

        $ads = Ad::with('account.connection')
            ->whereHas('account', fn ($a) => $a->where('is_tracked', true))
            ->whereHas('insights', fn ($i) => $i->where('date', '>=', now()->subDays((int) $this->option('days'))->toDateString())->where('spend', '>', 0))
            ->get()
            ->groupBy('facebook_ad_account_id');

        foreach ($ads as $group) {
            $token = $group->first()->account->connection->access_token;

            foreach ($group->chunk(50) as $chunk) {
                $urls = $chunk->map(fn ($ad) => $ad->ad_id.'?'.http_build_query(['fields' => 'creative.thumbnail_width(480).thumbnail_height(480){thumbnail_url}']))->values()->all();

                try {
                    $results = $graph->batch($urls, $token);
                } catch (\Throwable $e) {
                    $this->warn($e->getMessage());

                    continue;
                }

                foreach ($results as $row) {
                    $url = $row['creative']['thumbnail_url'] ?? null;
                    if ($url && isset($row['id'])) {
                        Ad::where('ad_id', $row['id'])->update(['thumbnail_url' => $url]);
                        $updated++;
                    }
                }
            }
        }

        $this->info("Yangilandi: {$updated} ta rasm");

        return self::SUCCESS;
    }
}
