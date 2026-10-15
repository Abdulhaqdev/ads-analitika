<?php

namespace App\Services;

/**
 * Every campaign type has its own "result": leads, direct messages, or traffic.
 * Cost and verdicts must be judged on that result, never on leads alone.
 */
class ResultType
{
    public const LABELS = ['leads' => 'Lid', 'messages' => 'Xabar (direct)', 'traffic' => 'Trafik (klik)', 'landing' => 'Landing view', 'other' => 'Boshqa'];

    public const SHORT = ['leads' => 'Lid', 'messages' => 'Xabar', 'traffic' => 'Klik', 'landing' => 'Landing', 'other' => '—'];

    public const COST = ['leads' => 'Lid narxi', 'messages' => 'Xabar narxi', 'traffic' => 'Klik narxi', 'landing' => 'Landing view narxi', 'other' => 'Narx'];

    public const ICON = ['leads' => '🎯', 'messages' => '💬', 'traffic' => '🔗', 'landing' => '🌐', 'other' => '•'];

    /** Type from campaign objective / optimization goal; null when it cannot be told. */
    public static function fromGoal(?string $goal, ?string $objective): ?string
    {
        $goal = strtoupper((string) $goal);
        $obj = strtoupper((string) $objective);

        return match (true) {
            in_array($goal, ['CONVERSATIONS', 'REPLIES', 'MESSAGING_PURCHASE_CONVERSION', 'MESSAGING_APPOINTMENT_CONVERSION'], true), $obj === 'MESSAGES' => 'messages',
            in_array($goal, ['LEAD_GENERATION', 'QUALITY_LEAD', 'OFFSITE_CONVERSIONS'], true) => 'leads',
            $goal === 'LANDING_PAGE_VIEWS' => 'landing',
            in_array($goal, ['LINK_CLICKS', 'CLICKS', 'PROFILE_VISIT', 'VISIT_INSTAGRAM_PROFILE'], true) => 'traffic',
            in_array($goal, ['REACH', 'IMPRESSIONS', 'AD_RECALL_LIFT', 'THRUPLAY', 'VIDEO_VIEWS', 'TWO_SECOND_CONTINUOUS_VIDEO_VIEWS', 'POST_ENGAGEMENT', 'PAGE_LIKES', 'ENGAGED_USERS', 'EVENT_RESPONSES'], true) => 'other',
            in_array($obj, ['OUTCOME_LEADS', 'LEAD_GENERATION'], true) => 'leads',
            in_array($obj, ['OUTCOME_TRAFFIC', 'LINK_CLICKS', 'TRAFFIC'], true) => 'traffic',
            in_array($obj, ['OUTCOME_AWARENESS', 'BRAND_AWARENESS', 'REACH', 'VIDEO_VIEWS', 'POST_ENGAGEMENT'], true) => 'other',
            default => null,
        };
    }

    /**
     * Fallback when the goal is unknown: whatever the ad actually produced.
     *
     * @param  array<string, int|float>  $totals  leads, messages, link_clicks
     */
    public static function infer(array $totals): string
    {
        return match (true) {
            ($totals['leads'] ?? 0) > 0 => 'leads',
            ($totals['messages'] ?? 0) > 0 => 'messages',
            ($totals['link_clicks'] ?? 0) > 0 => 'traffic',
            default => 'other',
        };
    }

    /** @param  array<string, int|float>  $row  leads, messages, link_clicks, landing_views */
    public static function results(?string $type, array $row): int
    {
        return (int) match ($type) {
            'messages' => $row['messages'] ?? 0,
            'traffic' => $row['link_clicks'] ?? 0,
            'landing' => $row['landing_views'] ?? 0,
            'other' => 0,
            default => $row['leads'] ?? 0,
        };
    }
}
