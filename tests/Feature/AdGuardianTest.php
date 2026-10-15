<?php

namespace Tests\Feature;

use App\Models\AdGuard;
use App\Models\FacebookAdAccount;
use App\Models\FacebookConnection;
use App\Services\AdGuardian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdGuardianTest extends TestCase
{
    use RefreshDatabase;

    private function guard(): AdGuard
    {
        $connection = FacebookConnection::forceCreate(['facebook_user_id' => '1', 'name' => 'Test', 'access_token' => 'token']);
        $account = FacebookAdAccount::forceCreate(['facebook_connection_id' => $connection->id, 'account_id' => '111', 'name' => 'Test', 'is_tracked' => true]);

        return AdGuard::create(['facebook_ad_account_id' => $account->id, 'campaign_id' => 'C1', 'campaign_name' => 'Palatka', 'max_cpl' => 1.5, 'min_spend' => 3]);
    }

    private function row(string $id, float $spend, int $leads): array
    {
        return ['ad_id' => $id, 'ad_name' => $id, 'adset_name' => 'men', 'spend' => (string) $spend,
            'actions' => $leads ? [['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => (string) $leads], ['action_type' => 'lead', 'value' => (string) $leads]] : []];
    }

    public function test_only_ads_over_the_limit_with_enough_spend_are_switched_off(): void
    {
        $verdicts = app(AdGuardian::class)->verdicts($this->guard(), [
            $this->row('cheap', 10, 8),      // $1.25 — fine
            $this->row('dear', 8, 4),        // $2.00 — over
            $this->row('young', 2.5, 0),     // too little spend to judge
            $this->row('silent', 3.2, 1),    // $3.20 per lead — over
            $this->row('empty', 3.1, 0),     // no lead after two limits of spend
        ]);

        $this->assertSame(['dear', 'silent', 'empty'], array_column($verdicts, 'ad_id'));
        $this->assertSame(2.0, $verdicts[0]['cpl']);
        $this->assertSame(4, $verdicts[0]['leads']);
    }

    public function test_budget_grows_while_leads_are_cheap_and_shrinks_back_when_they_get_dear(): void
    {
        $guard = $this->guard();
        $guard->update(['base_budget' => 10, 'max_budget' => 20]);
        $g = app(AdGuardian::class);

        $this->assertSame(12.0, $g->budgetStep($guard, 20, 18, 10)['budget']);     // $1.11 per lead
        $this->assertSame(20.0, $g->budgetStep($guard, 20, 18, 18)['budget']);     // capped at max
        $this->assertNull($g->budgetStep($guard, 20, 18, 20));                     // already at max
        $this->assertNull($g->budgetStep($guard, 20, 14, 10));                     // $1.43 — keep
        $this->assertSame(12.0, $g->budgetStep($guard, 20, 10, 15)['budget']);     // $2.00 — back down
        $this->assertNull($g->budgetStep($guard, 20, 10, 10));                     // never under base
        $this->assertNull($g->budgetStep($guard, 8, 8, 10));                       // too little spend

        $guard->update(['scaled_at' => now()->subHours(3)]);
        $this->assertNull($g->budgetStep($guard->fresh(), 20, 18, 10));            // one step a day
    }

    public function test_an_ad_is_paused_once_and_not_again_after_the_owner_turns_it_back_on(): void
    {
        $guard = $this->guard();
        Http::fake([
            '*/insights*' => Http::response(['data' => [$this->row('dear', 8, 4), $this->row('cheap', 10, 8)]]),
            '*' => Http::response(['success' => true]),
        ]);

        $first = app(AdGuardian::class)->run($guard);
        $second = app(AdGuardian::class)->run($guard->fresh());

        $this->assertSame(['dear'], array_column($first, 'ad_id'));
        $this->assertSame([], $second);
        Http::assertSentCount(3); // two insights reads, one pause
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/dear') && $r['status'] === 'PAUSED');
        $this->assertDatabaseHas('ad_guard_actions', ['ad_id' => 'dear', 'result' => 'paused', 'leads' => 4]);
    }
}
