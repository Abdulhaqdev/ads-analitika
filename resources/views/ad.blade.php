<x-layouts.app :title="$ad->name.' · Analitik agentim'">
    @php
        $genderName = fn ($g) => ['male' => 'Erkak', 'female' => 'Ayol', 'unknown' => "Noma'lum"][$g] ?? $g;
        $add = fn ($rows, $fn) => collect($rows)->map(fn ($r) => ['label' => $fn($r)] + $r)->all();
        $freq = (float) $ad->frequency_7d;
        $rank = fn ($v) => $v ? ['ABOVE_AVERAGE' => 'Yuqori', 'AVERAGE' => "O'rtacha", 'BELOW_AVERAGE_35' => 'Past (pastki 35%)', 'BELOW_AVERAGE_20' => 'Past (pastki 20%)', 'BELOW_AVERAGE_10' => 'Juda past (pastki 10%)'][$v] ?? $v : '—';
        $rankColor = fn ($v) => str_starts_with((string) $v, 'BELOW') ? 'text-red-600' : ($v === 'ABOVE_AVERAGE' ? 'text-emerald-600' : '');
    @endphp

    <a href="{{ route('accounts.show', $ad->account) }}" class="text-sm text-indigo-600">← {{ $ad->account->name }}</a>

    <div class="flex flex-wrap items-start gap-4 mt-3 mb-5">
        @if($ad->thumbnail_url)<img src="{{ $ad->thumbnail_url }}" class="w-24 h-24 rounded-lg object-cover" alt="">@endif
        <div class="flex-1 min-w-0">
            <h1 class="text-xl font-semibold">{{ $ad->name }}</h1>
            <p class="text-sm text-slate-500">{{ $ad->account->name }} · {{ $ad->campaign_name }} · {{ $ad->adset_name }}</p>
            <p class="text-sm mt-1">
                <span class="px-2 py-0.5 rounded-full text-xs {{ $ad->isActive() ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $ad->effective_status }}</span>
                {{ $ad->is_video ? 'Video' : 'Rasm/boshqa' }}
                @if($ad->adset_daily_budget) · Kunlik byudjet: {{ number_format($ad->adset_daily_budget, 2) }} @endif
                @if($ad->optimization_goal) · Optimallashtirish: {{ $ad->optimization_goal }} @endif
            </p>
        </div>
        <a href="{{ $ad->adsManagerUrl() }}" target="_blank" rel="noopener" class="text-sm px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-medium">Ads Manager ↗</a>
        <form method="GET">
            <select name="days" onchange="this.form.submit()" class="border border-slate-300 rounded-md px-2 py-1.5 text-sm bg-white">
                @foreach([7, 14, 30] as $d)<option value="{{ $d }}" @selected($days === $d)>Oxirgi {{ $d }} kun</option>@endforeach
            </select>
        </form>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3 mb-5">
        <x-kpi label="Sarf" :value="$total['spend']" prefix="$" />
        <x-kpi label="Lidlar" :value="$total['leads']" :decimals="0" />
        <x-kpi label="Lid narxi" :value="$total['cpl']" prefix="$" />
        <x-kpi label="CTR (link)" :value="$total['ctr']" suffix="%" />
        <x-kpi label="CPM" :value="$total['cpm']" prefix="$" />
        <x-kpi label="Klikdan lidga" :value="$total['conv']" suffix="%" />
        <x-kpi label="Hook rate" :value="$ad->is_video ? $total['hook'] : null" suffix="%" :decimals="1" />
        <x-kpi label="Hold rate" :value="$ad->is_video ? $total['hold'] : null" suffix="%" :decimals="1" />
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3 mb-5">
        <x-kpi label="CPC (link)" :value="$total['cpc']" prefix="$" />
        <x-kpi label="Landing view" :value="$total['landing_views']" :decimals="0" />
        <x-kpi label="Landing/klik" :value="$total['lpv_rate']" suffix="%" :decimals="1" />
        <x-kpi label="Lid rate" :value="$total['lead_rate']" suffix="%" />
        <x-kpi label="ThruPlay narxi" :value="$ad->is_video ? $total['cost_thruplay'] : null" prefix="$" :decimals="3" />
        <x-kpi label="O'rtacha ko'rish" :value="$ad->is_video ? $total['avg_watch'] : null" suffix=" s" :decimals="1" />
        <x-kpi label="100% ko'rgan" :value="$ad->is_video ? $total['p100'] : null" suffix="%" :decimals="1" />
        <x-kpi label="Ijtimoiy faollik" :value="$total['social_rate']" suffix="%" :decimals="3" />
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mb-5">
        <div class="lg:col-span-2 bg-white rounded-lg shadow-sm p-4">
            <h2 class="font-semibold mb-2">Tashxis</h2>
            @if($reasons)
                <p class="text-sm text-slate-600 mb-1">Oxirgi {{ config('analytics.recent_days') }} kun oldingi {{ config('analytics.baseline_days') }} kunga nisbatan:</p>
                <ul class="text-sm space-y-1 list-disc pl-5">@foreach($reasons as $r)<li>{{ $r }}</li>@endforeach</ul>
                <p class="text-sm mt-3"><span class="font-medium">Tavsiya:</span> {{ $recommendation }}</p>
            @else
                <p class="text-sm text-slate-600">Hozircha jiddiy muammo topilmadi.</p>
            @endif
            <table class="w-full text-sm mt-4">
                <thead class="text-xs text-slate-500 text-left border-b"><tr><th class="py-1">Ko'rsatkich</th><th class="text-right">Oldingi {{ config('analytics.baseline_days') }} kun</th><th class="text-right">Oxirgi {{ config('analytics.recent_days') }} kun</th></tr></thead>
                <tbody>
                @foreach(['spend' => 'Sarf', 'leads' => 'Lid', 'cpl' => 'Lid narxi', 'ctr' => 'CTR %', 'cpm' => 'CPM', 'lpv_rate' => 'Landing/klik %', 'lead_rate' => 'Lid rate %', 'hook' => 'Hook %', 'hold' => 'Hold %'] as $k => $l)
                    <tr class="border-b last:border-0"><td class="py-1">{{ $l }}</td><td class="text-right">{{ $base[$k] ?? '—' }}</td><td class="text-right font-medium">{{ $recent[$k] ?? '—' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4 text-sm space-y-2">
            <h2 class="font-semibold">Sifat ko'rsatkichlari</h2>
            <p>Chastota (7 kun): <span class="font-medium {{ $freq >= config('analytics.frequency_bad') ? 'text-red-600' : ($freq >= config('analytics.frequency_warn') ? 'text-amber-600' : '') }}">{{ $freq ?: '—' }}</span></p>
            <p>Chastota (butun davr): <span class="font-medium">{{ $ad->frequency_total ?: '—' }}</span></p>
            <p>Sifat reytingi: <span class="font-medium {{ $rankColor($ad->quality_ranking) }}">{{ $rank($ad->quality_ranking) }}</span></p>
            <p>Qiziqish reytingi: <span class="font-medium {{ $rankColor($ad->engagement_ranking) }}">{{ $rank($ad->engagement_ranking) }}</span></p>
            <p>Konversiya reytingi: <span class="font-medium {{ $rankColor($ad->conversion_ranking) }}">{{ $rank($ad->conversion_ranking) }}</span></p>
            @if($ad->creative_title || $ad->creative_body)
                <div class="pt-2 border-t">
                    <p class="font-medium">Kreativ matni</p>
                    @if($ad->creative_title)<p>{{ $ad->creative_title }}</p>@endif
                    @if($ad->creative_body)<p class="text-slate-600 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($ad->creative_body, 300) }}</p>@endif
                    @if($ad->cta_type)<p class="text-xs text-slate-500 mt-1">Tugma: {{ $ad->cta_type }}</p>@endif
                    @if($ad->link_url)<p class="text-xs text-slate-500 break-all">Lending: {{ $ad->link_url }}</p>@endif
                </div>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 mb-5">
        <h2 class="font-semibold mb-2">Kunlik dinamika</h2>
        <div class="h-64"><canvas id="daily"></canvas></div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
        <x-breakdown title="Soatlar" :rows="$add($hours, fn ($r) => sprintf('%02d:00', $r['hour']))" :min-leads="2" />
        <x-breakdown title="Jins va yosh" :rows="$add($ageGender->sortByDesc('spend'), fn ($r) => $genderName($r['gender']).', '.$r['age'])" :min-leads="2" />
        <x-breakdown title="Joylashuv" :rows="$add($placements->sortByDesc('spend'), fn ($r) => ucfirst($r['platform'] ?? '?').' · '.str_replace('_', ' ', $r['position'] ?? '?'))" />
        <x-breakdown title="Qurilma" :rows="$add($devices->sortByDesc('spend'), fn ($r) => str_replace('_', ' ', $r['device'] ?? '?'))" />
        <div class="lg:col-span-2"><x-breakdown title="Hudud" :rows="$add($regions->sortByDesc('spend'), fn ($r) => $r['region'] ?? '?')" :min-leads="2" /></div>
    </div>

    <script>
        new Chart(document.getElementById('daily'), {
            data: {
                labels: @json($daily->pluck('date')->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d.m'))),
                datasets: [
                    { type: 'bar', label: 'Sarf', data: @json($daily->pluck('spend')), backgroundColor: '#93c5fd', yAxisID: 'y' },
                    { type: 'line', label: 'Lid narxi', data: @json($daily->pluck('cpl')), borderColor: '#dc2626', backgroundColor: '#dc2626', tension: .3, yAxisID: 'y1', spanGaps: true },
                    { type: 'line', label: 'Hook %', data: @json($daily->pluck('hook')), borderColor: '#7c3aed', backgroundColor: '#7c3aed', tension: .3, yAxisID: 'y2', spanGaps: true, hidden: {{ $ad->is_video ? 'false' : 'true' }} },
                    { type: 'line', label: 'Hold %', data: @json($daily->pluck('hold')), borderColor: '#0891b2', backgroundColor: '#0891b2', tension: .3, yAxisID: 'y2', spanGaps: true, hidden: {{ $ad->is_video ? 'false' : 'true' }} },
                ],
            },
            options: { maintainAspectRatio: false, scales: { y1: { position: 'right', grid: { drawOnChartArea: false } }, y2: { display: false } } },
        });
    </script>
</x-layouts.app>
