@props(['row'])
@php
    $ad = $row['ad']; $m = $row['m']; $v = $row['verdict']; $c = config('analytics');
    $tone = ['good' => ['ring-emerald-200 bg-emerald-50/40', 'bg-emerald-600', 'text-emerald-700'], 'ok' => ['ring-slate-200 bg-white', 'bg-slate-500', 'text-slate-700'], 'bad' => ['ring-red-200 bg-red-50/40', 'bg-red-600', 'text-red-700'], 'idle' => ['ring-slate-200 bg-white', 'bg-slate-400', 'text-slate-500']][$v['level']];
    $gauge = function ($val, $good, $bad, $scale = 50) {
        if ($val === null) { return [0, 'bg-slate-200']; }
        return [max(3, min(100, round($val / $scale * 100))), $val >= $good ? 'bg-emerald-500' : ($val >= $bad ? 'bg-amber-400' : 'bg-red-500')];
    };
    [$hookW, $hookC] = $gauge($m['hook'], $c['hook_good'], $c['hook_bad']);
    [$holdW, $holdC] = $gauge($m['hold'], $c['hold_good'], $c['hold_bad']);
@endphp
<div class="rounded-xl ring-1 {{ $tone[0] }} p-2 flex gap-2.5 items-center">
    <a href="{{ route('ads.show', $ad) }}" class="relative w-14 h-14 rounded-lg overflow-hidden bg-slate-200 shrink-0 block">
        @if($ad->thumbnail_url)<img src="{{ $ad->thumbnail_url }}" alt="" loading="lazy" class="w-full h-full object-cover">@endif
        <span class="absolute inset-0 grid place-items-center"><span class="w-5 h-5 rounded-full bg-black/55 text-white grid place-items-center text-[8px] pl-0.5">▶</span></span>
        <span class="absolute left-0.5 top-0.5 text-[9px] font-bold px-1 rounded text-white {{ $tone[1] }}">{{ $v['label'] }}</span>
    </a>
    <div class="flex-1 min-w-0">
        <div class="flex items-baseline gap-2 min-w-0">
            <a href="{{ route('ads.show', $ad) }}" class="font-semibold text-slate-900 truncate hover:underline">{{ $ad->name }}</a>
        </div>
        <p class="text-[10px] text-slate-400 truncate">{{ $ad->campaign_name }}</p>
        <div class="leading-none mt-0.5">
            <span class="num text-base font-extrabold {{ $tone[2] }}">{{ $m['cpl'] ? '$'.number_format($m['cpl'], 2) : '—' }}</span>
            <span class="num text-[11px] text-slate-400 ml-1.5">{{ $m['leads'] }} lid · ${{ number_format($m['spend'], 0) }} sarf</span>
        </div>
        <div class="mt-1.5 space-y-1">
            <div class="flex items-center gap-2 text-[11px]"><span class="w-9 text-slate-500">Hook</span><div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><div class="h-1.5 rounded-full {{ $hookC }}" style="width: {{ $hookW }}%"></div></div><span class="num w-11 text-right font-semibold text-slate-700">{{ $m['hook'] !== null ? $m['hook'].'%' : '—' }}</span></div>
            <div class="flex items-center gap-2 text-[11px]"><span class="w-9 text-slate-500">Hold</span><div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><div class="h-1.5 rounded-full {{ $holdC }}" style="width: {{ $holdW }}%"></div></div><span class="num w-11 text-right font-semibold text-slate-700">{{ $m['hold'] !== null ? $m['hold'].'%' : '—' }}</span></div>
        </div>
    </div>
</div>
