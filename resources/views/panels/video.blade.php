@php
    $order = ['bad' => 0, 'ok' => 1, 'good' => 2, 'idle' => 3];
    $sorted = $ads->sortBy([fn ($a, $b) => $order[$a['verdict']['level']] <=> $order[$b['verdict']['level']], fn ($a, $b) => $b['m']['spend'] <=> $a['m']['spend']])->values();
    $cfg = config('analytics');
@endphp
@if($ads->isEmpty())
    <p class="text-sm text-slate-400 py-10 text-center">Bu davrda video reklama ma'lumoti yo'q.</p>
@else
    <div class="grid lg:grid-cols-3 gap-5 mb-6">
        <div class="lg:col-span-2">
            <p class="text-sm font-semibold text-slate-700 mb-1">Tomoshabin qayerda ketadi</p>
            <x-chart :config="[
                'type' => 'line',
                'data' => ['labels' => collect($retention)->pluck(0)->all(), 'datasets' => [['label' => 'Ko\'rayotganlar %', 'data' => collect($retention)->pluck(1)->all(), 'borderColor' => '#6366f1', 'backgroundColor' => 'rgba(99,102,241,.15)', 'fill' => true, 'tension' => .3]]],
                'options' => ['maintainAspectRatio' => false, 'scales' => ['y' => ['min' => 0, 'max' => 100]], 'plugins' => ['legend' => ['display' => false]]],
            ]" height="h-40" />
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-1 gap-2.5 content-start">
            <x-kpi label="Hook rate" :value="$t['hook']" suffix="%" :decimals="1" hint="30%+ yaxshi" />
            <x-kpi label="Hold rate" :value="$t['hold']" suffix="%" :decimals="1" hint="25%+ yaxshi" />
        </div>
    </div>

    <p class="text-xs text-slate-400 mb-2.5">Eng muammolisi birinchi. Hook — birinchi 3 soniyada to'xtab qolganlar ({{ $cfg['hook_good'] }}%+ yaxshi). Hold — oxirigacha ko'rganlar ({{ $cfg['hold_good'] }}%+ yaxshi).</p>
    <div class="grid xl:grid-cols-2 gap-3">
        @foreach($sorted->take(12) as $row)<x-video-card :row="$row" />@endforeach
    </div>
    @if($sorted->count() > 12)
        <details class="mt-3"><summary class="cursor-pointer text-sm text-indigo-600 font-medium select-none">Yana {{ $sorted->count() - 12 }} ta video ▾</summary>
            <div class="grid xl:grid-cols-2 gap-3 mt-3">@foreach($sorted->slice(12) as $row)<x-video-card :row="$row" />@endforeach</div></details>
    @endif
@endif
