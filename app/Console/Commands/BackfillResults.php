<?php

namespace App\Console\Commands;

use App\Models\Ad;
use App\Models\AdInsight;
use App\Services\ResultType;
use Illuminate\Console\Command;

class BackfillResults extends Command
{
    protected $signature = 'analytics:backfill-results';

    protected $description = 'Natija turi va natija sonini eski (qayta yuklanmagan) qatorlar uchun to\'ldiradi';

    public function handle(): int
    {
        $fixed = 0;

        Ad::whereNull('result_type')->chunkById(200, function ($ads) use (&$fixed) {
            foreach ($ads as $ad) {
                $totals = AdInsight::where('ad_id', $ad->id)->selectRaw('SUM(leads) leads, SUM(messages) messages, SUM(link_clicks) link_clicks')->first();
                $type = ResultType::fromGoal($ad->optimization_goal, $ad->objective)
                    ?? ResultType::infer($totals?->toArray() ?? []);
                $ad->update(['result_type' => $type]);

                AdInsight::where('ad_id', $ad->id)->get()->each(function ($row) use ($type) {
                    $row->update(['results' => ResultType::results($type, $row->only(['leads', 'messages', 'link_clicks', 'landing_views']))]);
                });
                $fixed++;
            }
        });

        // rows of already-typed ads that were stored before the results column existed
        Ad::whereNotNull('result_type')->whereHas('insights', fn ($q) => $q->where('results', 0)->where(fn ($w) => $w->where('leads', '>', 0)->orWhere('link_clicks', '>', 0)->orWhere('messages', '>', 0)))
            ->chunkById(200, function ($ads) {
                foreach ($ads as $ad) {
                    AdInsight::where('ad_id', $ad->id)->where('results', 0)->get()->each(function ($row) use ($ad) {
                        $row->update(['results' => ResultType::results($ad->result_type, $row->only(['leads', 'messages', 'link_clicks', 'landing_views']))]);
                    });
                }
            });

        $this->info("Yangilandi: {$fixed} ta reklama");

        return self::SUCCESS;
    }
}
