@php
    $rows = collect($rows);
    $eligible = $rows->filter(fn ($r) => ($r['cpl'] ?? null) !== null && $r['leads'] >= 3);
    $sorted = $eligible->sortBy('cpl');
    $median = $sorted->isNotEmpty() ? $sorted->values()->get(intdiv($sorted->count(), 2))['cpl'] : null;
    $maxLeads = max(1, (int) $rows->max('leads'));
    $best = $sorted->first();
    $worst = $eligible->count() > 1 ? $sorted->last() : null;
    $unknown = fn ($r) => strtolower((string) ($r['age'] ?? '')) === 'unknown' || strtolower((string) ($r['gender'] ?? '')) === 'unknown';
    if ($best && $unknown($best)) { $best = $sorted->first(fn ($r) => ! $unknown($r)); }
    if ($worst && $unknown($worst)) { $worst = $sorted->reverse()->first(fn ($r) => ! $unknown($r)); }
@endphp
<div>
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 mb-3">
        <h3 class="font-semibold text-slate-900">{{ $title }}</h3>
        <p class="text-xs text-slate-400">{{ $hint }}</p>
    </div>

    @if($rows->sum('spend') <= 0)
        <p class="text-sm text-slate-400 py-6 text-center">Ma'lumot yo'q. Akkauntni yuklang yoki bir necha kundan keyin qayta qarang.</p>
    @else
        @if($best || $worst)
            <div class="flex flex-wrap gap-2 mb-3 text-xs">
                @if($best)<span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">Eng arzon: <b>{{ $best['label'] }}</b> · {{ number_format($best['cpl'], 2) }}</span>@endif
                @if($worst)<span class="px-2.5 py-1 rounded-full bg-red-50 text-red-700 border border-red-200">Eng qimmat: <b>{{ $worst['label'] }}</b> · {{ number_format($worst['cpl'], 2) }}</span>@endif
            </div>
        @endif

        @if($chart)
            <div class="mb-4"><x-chart :config="$chart" height="h-56" /></div>
        @endif

        <div class="space-y-1.5">
            @foreach($rows as $r)
                @php
                    $cpl = $r['cpl'] ?? null;
                    $tone = ($cpl !== null && $r['leads'] >= 3 && $median)
                        ? ($cpl <= $median * 0.85 ? 'bg-emerald-500' : ($cpl >= $median * 1.2 ? 'bg-red-500' : 'bg-indigo-400'))
                        : 'bg-slate-300';
                    $width = $r['leads'] > 0 ? max(2, round($r['leads'] / $maxLeads * 100)) : 0;
                @endphp
                <div class="grid grid-cols-[8rem_1fr_auto] sm:grid-cols-[11rem_1fr_15rem] items-center gap-3 text-sm">
                    <span class="truncate text-slate-700" title="{{ $r['label'] }}">{{ $r['label'] }}</span>
                    <div class="h-5 rounded bg-slate-100 overflow-hidden"><div class="h-5 rounded {{ $tone }}" style="width: {{ $width }}%"></div></div>
                    <span class="num text-xs text-slate-500 text-right whitespace-nowrap">
                        <b class="text-slate-800">{{ $r['leads'] }}</b> lid
                        · <b class="text-slate-800">{{ $cpl !== null ? number_format($cpl, 2) : '—' }}</b>
                        · {{ number_format($r['spend'], 0) }}
                        <span class="hidden sm:inline">· {{ $r['ctr'] !== null ? $r['ctr'].'%' : '—' }}</span>
                    </span>
                </div>
            @endforeach
        </div>
        <p class="text-[11px] text-slate-400 mt-3">Chiziq uzunligi: lidlar soni. Rang: <span class="text-emerald-600">yashil</span> arzon, <span class="text-red-600">qizil</span> qimmat (kamida 3 ta lid bo'lganda). Ustunlar: lid · lid narxi · sarf · CTR.
            @if(($total ?? 0) > $rows->count()) {{ $rows->count() }} / {{ $total }} ko'rsatilgan (sarfi eng ko'plari). @endif</p>
    @endif
</div>
