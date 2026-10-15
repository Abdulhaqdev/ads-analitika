<?php

return [
    // Hook rate = 3 soniyalik ko'rishlar / ko'rsatuvlar (%)
    'hook_good' => 30,
    'hook_bad' => 20,

    // Hold rate = ThruPlay / 3 soniyalik ko'rishlar (%)
    'hold_good' => 25,
    'hold_bad' => 12,

    'frequency_warn' => 2.5,
    'frequency_bad' => 3.5,

    'ctr_bad' => 0.8,
    'ctr_good' => 1.5,

    // Landing view / link klik (%). Past bo'lsa lending sekin yoki klik sifatsiz
    'lpv_rate_bad' => 70,
    'lpv_rate_good' => 80,

    // Lid / landing view (%)
    'lead_rate_bad' => 8,
    'lead_rate_good' => 12,

    // CPL 3 kunlik oynada oldingi 7 kunga nisbatan shuncha marta oshsa ogohlantiriladi
    'cpl_spike_warn' => 1.3,
    'cpl_spike_critical' => 1.6,

    // Kamida shuncha sarf bo'lmasa xulosa chiqarilmaydi (akkaunt valyutasida)
    'min_spend' => 3,
    'no_lead_spend' => 8,

    // Trafik reklamalari: faqat bitta klik narxi (valyutada). Ko'rishlar soni hisobga olinmaydi.
    'traffic_cpc_good' => 0.04,
    'traffic_cpc_bad' => 0.06,

    // Direct (xabar) reklamalari: bitta xabar narxi. null bo'lsa akkauntning o'z o'rtachasi bilan solishtiriladi.
    'message_cost_good' => 0.30,
    'message_cost_bad' => 0.45,

    'recent_days' => 3,
    'baseline_days' => 7,

    'telegram_token' => env('TELEGRAM_BOT_TOKEN'),
    'telegram_chat_id' => env('TELEGRAM_CHAT_ID'),
];
