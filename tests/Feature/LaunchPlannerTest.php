<?php

namespace Tests\Feature;

use App\Services\DateRange;
use App\Services\LaunchPlanner;
use Illuminate\Http\Request;
use Tests\TestCase;

class LaunchPlannerTest extends TestCase
{
    public function test_product_key_ignores_numbers_budgets_and_setup_words(): void
    {
        $planner = app(LaunchPlanner::class);

        $this->assertSame('bolalar palatka', $planner->productKey('4 / Bolalar palatka / 11$ — 1'));
        $this->assertSame('bolalar palatka', $planner->productKey('Bolalar palatka / NEW / video 3 / 4x15$ / 13.10.26'));
        $this->assertSame('soyali quron', $planner->productKey('1 / 169 Soyali quron / ABO / 11*11 — 1'));
        $this->assertSame('palatka', $planner->productKey('BID | PALATKA | ABO'));
        $this->assertSame('zikir ring', $planner->productKey('zikir ring 7$ - 1'));
        $this->assertSame('quron mazayka', $planner->productKey('Quron mazayka / cbo — cost/per'));
    }

    public function test_period_sent_in_the_form_body_is_used_by_the_prepare_request(): void
    {
        $request = Request::create('/accounts/1/launch', 'POST', ['r' => '30']);
        $request->query->add($request->only('r', 'from', 'to'));

        $this->assertSame(30, DateRange::fromRequest($request)->days());
    }

    public function test_hand_picked_groups_and_videos_are_read_from_the_form(): void
    {
        $opts = LaunchPlanner::options(['pick_adsets' => ['120250829737270466', 'x'], 'pick_videos' => ['120250829744650466']]);

        $this->assertSame(['120250829737270466'], $opts['pick_adsets']);
        $this->assertSame(['120250829744650466'], $opts['pick_videos']);
        $this->assertNull(LaunchPlanner::options([])['pick_adsets']);
        $this->assertNull(LaunchPlanner::options(['pick_videos' => ['abc']])['pick_videos']);
    }
}
