@props(['card', 'showAccount' => false])
@php
    $ad = $card['ad'];
    $kind = $card['kind'];
    $style = [
        'off' => ['ring-red-200 bg-red-50/50', 'bg-red-600', "O'chiring", 'text-red-700', '#dc2626'],
        'expensive' => ['ring-amber-200 bg-amber-50/40', 'bg-amber-500', 'Qimmat', 'text-amber-700', '#f59e0b'],
        'tired' => ['ring-amber-200 bg-amber-50/40', 'bg-amber-500', 'Charchadi', 'text-amber-700', '#f59e0b'],
        'good' => ['ring-emerald-200 bg-emerald-50/50', 'bg-emerald-600', 'Yaxshi', 'text-emerald-700', '#059669'],
    ][$kind];
    $spark = collect($card['spark'])->map(fn ($v) => $v === null ? null : (float) $v);
    $vals = $spark->filter(fn ($v) => $v !== null);
    $max = max(0.01, (float) $vals->max());
    $min = (float) ($vals->min() ?? 0);
    $n = max(1, $spark->count() - 1);
    $points = $spark->map(fn ($v, $i) => $v === null ? null : round($i / $n * 78 + 1, 1).','.round(22 - (($v - $min) / max(0.0001, $max - $min)) * 18, 1))->filter()->implode(' ');
    $icon = \App\Services\ResultType::ICON[$ad->resultType()] ?? '';
@endphp
<div class="rounded-xl ring-1 {{ $style[0] }} p-2 flex gap-2.5 items-center">
    <a href="{{ route('ads.show', $ad) }}" class="relative w-14 h-14 rounded-lg overflow-hidden bg-slate-200 shrink-0 block">
        @if($ad->thumbnail_url)<img src="{{ $ad->thumbnail_url }}" alt="" loading="lazy" class="w-full h-full object-cover">@endif
        @if($ad->is_video)<span class="absolute inset-0 grid place-items-center"><span class="w-5 h-5 rounded-full bg-black/55 text-white grid place-items-center text-[8px] pl-0.5">▶</span></span>@endif
        <span class="absolute left-0.5 top-0.5 text-[9px] font-bold px-1 rounded text-white {{ $style[1] }}">{{ $style[2] }}</span>
    </a>

    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-1.5 min-w-0">
            <a href="{{ route('ads.show', $ad) }}" class="text-[13px] font-semibold text-slate-900 truncate hover:underline">{{ $ad->name }}</a>
            <span class="text-[10px] text-slate-400 shrink-0" title="{{ \App\Services\ResultType::LABELS[$ad->resultType()] ?? '' }}">{{ $icon }} {{ $card['rlabel'] }}</span>
            @if($showAccount)<span class="text-[10px] text-slate-400 truncate">· {{ $ad->account->name }}</span>@endif
        </div>
        <div class="flex items-center gap-2 leading-none mt-0.5">
            <span class="num text-base font-extrabold {{ $style[3] }}">{{ $card['now'] ? '$'.number_format($card['now'], 2) : '—' }}</span>
            @if($card['before'])<span class="num text-[11px] text-slate-400">← ${{ number_format($card['before'], 2) }}</span>@endif
            @if($card['change'] !== null)<span class="num text-[11px] font-bold {{ $card['change'] > 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $card['change'] > 0 ? '▲' : '▼' }}{{ abs($card['change']) }}%</span>@endif
            @if($vals->count() > 1)
                <svg viewBox="0 0 80 24" class="w-16 h-5 shrink-0 hidden sm:block" aria-hidden="true"><polyline points="{{ $points }}" fill="none" stroke="{{ $style[4] }}" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/></svg>
            @endif
        </div>
        <p class="text-[12px] text-slate-600 leading-tight mt-1 truncate" title="{{ $card['cause'] }}">{{ $card['cause'] }}</p>
        <p class="text-[12px] font-semibold {{ $style[3] }} leading-tight truncate" title="{{ $card['action'] }}">→ {{ $card['action'] }}</p>
    </div>
</div>
