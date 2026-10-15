<?php

namespace Tests\Feature;

use App\Models\FacebookAdAccount;
use App\Models\FacebookConnection;
use App\Services\LaunchExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LaunchExecutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_is_created_paused_with_copied_targeting_and_exclusions(): void
    {
        $connection = FacebookConnection::forceCreate(['facebook_user_id' => '1', 'name' => 'Test', 'access_token' => 'token']);
        $account = FacebookAdAccount::forceCreate(['facebook_connection_id' => $connection->id, 'account_id' => '111', 'name' => 'Test', 'is_tracked' => true]);

        Http::fake([
            '*/campaigns' => Http::response(['id' => 'C1']),
            '*/adsets' => Http::response(['id' => 'S1']),
            '*/ads' => Http::response(['id' => 'A1']),
            '*' => Http::response(['id' => 'AD9', 'creative' => ['id' => 'CR9']]),
        ]);

        $result = app(LaunchExecutor::class)->prepare($account, [
            'objective' => 'OUTCOME_LEADS',
            'budget' => 15.0,
            'adsets' => [[
                'label' => 'maktab',
                'targeting' => ['age_min' => 18, 'geo_locations' => ['countries' => ['UZ']], 'excluded_custom_audiences' => [['id' => '7']],
                    'subscriber_universe' => ['messaging_customer_base_for_whatsapp' => ['id' => '1']]],
                'setup' => ['optimization_goal' => 'OFFSITE_CONVERSIONS', 'billing_event' => 'IMPRESSIONS', 'promoted_object' => ['pixel_id' => '5', 'custom_event_type' => 'LEAD']],
            ]],
            'creatives' => [['ad_id' => 'AD9', 'name' => 'video 1']],
            'audiences' => [['id' => '8', 'selected' => true], ['id' => '9', 'selected' => false]],
        ], 'Test / reja');

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame(['ok' => true, 'campaign_id' => 'C1', 'campaigns' => ['C1'], 'adsets' => 1, 'ads' => 1, 'error' => null, 'warning' => null], $result);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'act_111/campaigns') && $r['status'] === 'PAUSED');
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), 'act_111/adsets')) {
                return false;
            }
            $targeting = json_decode($r['targeting'], true);

            return (int) $r['daily_budget'] === 1500
                && $r['bid_strategy'] === 'LOWEST_COST_WITHOUT_CAP'
                && $r['campaign_id'] === 'C1'
                && array_column($targeting['excluded_custom_audiences'], 'id') === ['7', '8']
                && ! isset($targeting['subscriber_universe']);
        });
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'act_111/ads') && json_decode($r['creative'], true) === ['creative_id' => 'CR9']);
    }

    public function test_lead_form_plan_reuses_form_creatives_and_sets_form_delivery(): void
    {
        $connection = FacebookConnection::forceCreate(['facebook_user_id' => '1', 'name' => 'Test', 'access_token' => 'token']);
        $account = FacebookAdAccount::forceCreate(['facebook_connection_id' => $connection->id, 'account_id' => '111', 'name' => 'Test', 'is_tracked' => true]);

        Http::fake([
            '*/campaigns' => Http::response(['id' => 'C1']),
            '*/adsets' => Http::response(['id' => 'S1']),
            '*/ads' => Http::response(['id' => 'A1']),
        ]);

        $result = app(LaunchExecutor::class)->prepare($account, [
            'objective' => 'OUTCOME_LEADS',
            'budget' => 20.0,
            'structure' => 'cbo',
            'dest' => 'form',
            'form' => '555000111',
            'adsets' => [['label' => 'men', 'targeting' => ['age_min' => 18],
                'setup' => ['optimization_goal' => 'LEAD_GENERATION', 'billing_event' => 'IMPRESSIONS', 'destination_type' => 'ON_AD', 'promoted_object' => ['page_id' => '42']]]],
            'creatives' => [['ad_id' => 'AD1', 'creative_id' => 'CR1', 'form_id' => '555000111', 'link' => null, 'name' => '1']],
            'audiences' => [],
        ], 'Cefr / CBO 20$');

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertNull($result['warning']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), 'adcreatives'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'act_111/adsets') && $r['optimization_goal'] === 'LEAD_GENERATION' && $r['destination_type'] === 'ON_AD');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'act_111/ads') && json_decode($r['creative'], true) === ['creative_id' => 'CR1']);
    }

    public function test_website_creative_cannot_silently_go_into_a_form_campaign(): void
    {
        $connection = FacebookConnection::forceCreate(['facebook_user_id' => '1', 'name' => 'Test', 'access_token' => 'token']);
        $account = FacebookAdAccount::forceCreate(['facebook_connection_id' => $connection->id, 'account_id' => '111', 'name' => 'Test', 'is_tracked' => true]);

        Http::fake([
            '*/adcreatives' => Http::response(['error' => ['message' => 'Invalid parameter', 'error_user_msg' => 'App in development mode', 'code' => 100]], 400),
            '*' => Http::response(['object_story_spec' => ['video_data' => ['video_id' => 'V', 'call_to_action' => ['type' => 'LEARN_MORE', 'value' => ['link' => 'https://site.test']]]]]),
        ]);

        $result = app(LaunchExecutor::class)->prepare($account, [
            'objective' => 'OUTCOME_LEADS', 'budget' => 20.0, 'structure' => 'cbo', 'dest' => 'form', 'form' => '555000111',
            'adsets' => [['label' => 'men', 'targeting' => [], 'setup' => []]],
            'creatives' => [['ad_id' => 'AD1', 'creative_id' => 'CR1', 'form_id' => null, 'link' => 'https://site.test', 'name' => '1']],
            'audiences' => [],
        ], 'Cefr');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['campaign_id']);
        $this->assertStringContainsString('lid formaga', (string) $result['error']);
    }

    public function test_cbo_split_puts_budget_on_each_campaign_and_keeps_creative_when_link_copy_is_refused(): void
    {
        $connection = FacebookConnection::forceCreate(['facebook_user_id' => '1', 'name' => 'Test', 'access_token' => 'token']);
        $account = FacebookAdAccount::forceCreate(['facebook_connection_id' => $connection->id, 'account_id' => '111', 'name' => 'Test', 'is_tracked' => true]);

        Http::fake([
            '*/adcreatives' => Http::response(['error' => ['message' => 'Invalid parameter', 'error_user_msg' => 'App in development mode', 'code' => 100]], 400),
            '*/campaigns' => Http::sequence()->push(['id' => 'C1'])->push(['id' => 'C2']),
            '*/adsets' => Http::response(['id' => 'S1']),
            '*/ads' => Http::response(['id' => 'A1']),
            '*' => Http::response(['object_story_spec' => ['video_data' => ['video_id' => 'V', 'call_to_action' => ['value' => ['link' => 'https://old.test']]]]]),
        ]);

        $result = app(LaunchExecutor::class)->prepare($account, [
            'objective' => 'OUTCOME_LEADS',
            'budget' => 20.0,
            'structure' => 'cbo',
            'split' => true,
            'link' => 'https://new.test/ab',
            'adsets' => [
                ['label' => 'men', 'targeting' => ['age_min' => 18], 'setup' => []],
                ['label' => 'women', 'targeting' => ['age_min' => 18], 'setup' => []],
            ],
            'creatives' => [
                ['ad_id' => 'AD1', 'creative_id' => 'CR1', 'link' => 'https://old.test', 'name' => '1'],
                ['ad_id' => 'AD2', 'creative_id' => 'CR2', 'link' => 'https://old.test', 'name' => '1'],
            ],
            'audiences' => [],
        ], 'Palatka / CBO 20$');

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame(['C1', 'C2'], $result['campaigns']);
        $this->assertSame(4, $result['adsets']);
        $this->assertSame(4, $result['ads']);
        $this->assertStringContainsString('App in development mode', (string) $result['warning']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'act_111/campaigns') && $r['status'] === 'PAUSED' && (int) $r['daily_budget'] === 2000 && $r['name'] === 'Palatka / CBO 20$ / video 2');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), 'act_111/adsets') && isset($r['daily_budget']));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'act_111/ads') && json_decode($r['creative'], true) === ['creative_id' => 'CR2']);
    }
}
