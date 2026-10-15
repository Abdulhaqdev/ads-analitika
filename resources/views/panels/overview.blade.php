@php
    $cur = $account->currency === 'USD' ? '$' : '';
    $ads = $report['ads'];
    $count = fn (string $l) => $ads->filter(fn ($r) => $r['verdict']['level'] === $l)->count();
    $noLead = $ads->filter(fn ($r) => $r['verdict']['level'] === 'bad' && $r['m']['leads'] === 0)->count();
    $labels = $daily->pluck('date')->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d.m'))->all();
    $line = fn ($label, $key, $color, $axis = 'y') => ['type' => 'line', 'label' => $label, 'data' => $daily->pluck($key)->all(), 'borderColor' => $color, 'backgroundColor' => $color, 'tension' => .3, 'yAxisID' => $axis, 'spanGaps' => true, 'pointRadius' => 2];
    $funnel = [
        ["Ko'rsatuv", $t['impressions'], null],
        ['Link kliklar', $t['link_clicks'], $t['ctr'] !== null ? $t['ctr'].'% CTR' : null],
        ['Landing view', $t['landing_views'], $t['lpv_rate'] !== null ? $t['lpv_rate'].'% klikdan' : null],
        ['Lidlar', $t['leads'], $t['lead_rate'] !== null ? $t['lead_rate'].'% lid rate' : null],
    ];
    $fmax = max(1, $t['impressions']);
    $pill = ['good' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'warn' => 'bg-amber-100 text-amber-800 border-amber-200', 'bad' => 'bg-red-100 text-red-800 border-red-200', 'idle' => 'bg-slate-100 text-slate-500 border-slate-200'];
    $pillText = ['good' => 'Yaxshi', 'warn' => "O'rtacha", 'bad' => 'Yomon', 'idle' => "Ma'lumot yo'q"];
    $card = ['good' => 'border-emerald-200 bg-emerald-50/60', 'warn' => 'border-amber-200 bg-amber-50/60', 'bad' => 'border-red-200 bg-red-50/60', 'idle' => 'border-slate-200 bg-white'];
@endphp

@if($t['spend'] <= 0)
    <p class="text-sm text-slate-400 py-10 text-center">Bu davrda sarf yo'q yoki ma'lumot hali yuklanmagan.</p>
@else
@php
    $ads = $report['ads'];
    $good = $ads->filter(fn ($r) => $r['verdict']['level'] === 'good')->count();
    $bad = $ads->filter(fn ($r) => $r['verdict']['level'] === 'bad')->count();
    $costDec = $t['rtype'] === 'traffic' ? 3 : 2;
    $cfgA = config('analytics');
    $norm = match ($t['rtype']) { 'traffic' => $cfgA['traffic_cpc_good'], 'messages' => $cfgA['message_cost_good'], 'leads' => $account->target_cpl, default => null };
    $costTone = ($t['cpl'] !== null && $norm) ? ($t['cpl'] <= $norm ? 'text-emerald-600' : ($t['cpl'] <= $norm * 1.5 ? 'text-amber-600' : 'text-red-600')) : 'text-slate-900';
@endphp

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <x-kpi label="Sarf" :value="$t['spend']" :prev="$p['spend']" :prefix="$cur" />
    <x-kpi :label="$t['rlabel'].' soni'" :value="$t['leads']" :prev="$p['leads']" :decimals="0" />
    <div class="bg-white rounded-2xl ring-1 ring-slate-200/70 shadow-sm p-3.5">
        <p class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">{{ $t['rcost'] }}</p>
        <div class="flex items-end justify-between gap-2 mt-1.5">
            <p class="num text-[22px] leading-none font-bold {{ $costTone }}">{{ $t['cpl'] !== null ? $cur.number_format($t['cpl'], $costDec) : '—' }}</p>
            @if($norm)<span class="num text-[11px] text-slate-400">norma ≤ {{ $cur }}{{ $norm }}</span>@endif
        </div>
    </div>
    <div class="bg-white rounded-2xl ring-1 ring-slate-200/70 shadow-sm p-3.5">
        <p class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Reklamalar holati</p>
        <p class="mt-1.5 text-sm"><b class="text-emerald-600">{{ $good }}</b> yaxshi · <b class="text-red-600">{{ $bad }}</b> qimmat <span class="text-slate-400">/ {{ $ads->count() }}</span></p>
    </div>
</div>

@if(count($t['types']) > 1)
    <div class="flex flex-wrap gap-2 mb-4 text-sm">
        @foreach($t['types'] as $tk => $tv)
            <span class="px-3 py-1.5 rounded-xl ring-1 ring-slate-200 bg-slate-50"><b>{{ \App\Services\ResultType::ICON[$tk] }} {{ number_format($tv['results']) }}</b> {{ mb_strtolower(\App\Services\ResultType::SHORT[$tk]) }} · <b>${{ $tv['cost'] ?? '—' }}</b> <span class="text-slate-400">narxi</span></span>
        @endforeach
    </div>
@endif

<p class="text-sm font-semibold text-slate-700 mb-1">Sarf va {{ mb_strtolower($t['rcost']) }}</p>
<x-chart :config="['type' => 'bar', 'data' => ['labels' => $labels, 'datasets' => [
    ['type' => 'bar', 'label' => 'Sarf', 'data' => $daily->pluck('spend')->all(), 'backgroundColor' => '#c7d2fe', 'yAxisID' => 'y'],
    $line($t['rcost'], 'cpl', '#dc2626', 'y1'),
]], 'options' => ['maintainAspectRatio' => false, 'scales' => ['y1' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false]]]]]" height="h-52" />

<details class="mt-5">
    <summary class="cursor-pointer text-sm text-indigo-600 font-medium select-none">Yana metrikalar ▾</summary>
    <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-2.5">
        <x-kpi label="CPM" :value="$t['cpm']" :prev="$p['cpm']" :lower-is-better="true" :prefix="$cur" />
        <x-kpi label="CTR (link)" :value="$t['ctr']" :prev="$p['ctr']" suffix="%" />
        <x-kpi label="Ko'rsatuvlar" :value="$t['impressions']" :prev="$p['impressions']" :decimals="0" />
        <x-kpi label="Chastota" :value="$freq" :lower-is-better="true" />
        @if($t['rtype'] !== 'traffic')
            <x-kpi label="Hook rate" :value="$t['hook']" :prev="$p['hook']" suffix="%" :decimals="1" />
            <x-kpi label="Hold rate" :value="$t['hold']" :prev="$p['hold']" suffix="%" :decimals="1" />
        @endif
        @if($t['rtype'] === 'leads')
            <x-kpi label="Landing / klik" :value="$t['lpv_rate']" :prev="$p['lpv_rate']" suffix="%" />
            <x-kpi label="Lid rate" :value="$t['lead_rate']" :prev="$p['lead_rate']" suffix="%" />
        @endif
    </div>
</details>
@endif
