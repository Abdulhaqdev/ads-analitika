@php
    $shown = $all ? $ads : $ads->take(30);
    $tone = ['good' => 'bg-emerald-100 text-emerald-800', 'ok' => 'bg-amber-100 text-amber-800', 'bad' => 'bg-red-100 text-red-800', 'idle' => 'bg-slate-100 text-slate-500'];
    $costColor = ['good' => 'text-emerald-600', 'ok' => 'text-amber-600', 'bad' => 'text-red-600', 'idle' => 'text-slate-700'];
@endphp
@if($ads->isEmpty())
    <p class="text-sm text-slate-400 py-10 text-center">Bu davrda sarf yo'q.</p>
@else
<p class="text-xs text-slate-400 mb-2">Ustun nomini bosing, saralanadi. Reklamani bossangiz, batafsil ochiladi.</p>
<div class="overflow-x-auto">
    <table class="w-full text-sm sortable">
        <thead class="text-[11px] uppercase tracking-wide text-slate-400 text-left border-b">
            <tr><th class="py-2">Reklama</th><th data-sort class="text-right">Sarf</th><th data-sort class="text-right">Natija</th><th data-sort class="text-right">Narxi</th><th data-sort class="text-right">Holat</th></tr>
        </thead>
        <tbody>
        @foreach($shown as $row)
            @php
                $ad = $row['ad']; $m = $row['m']; $v = $row['verdict'];
                $order = ['bad' => 0, 'ok' => 1, 'idle' => 2, 'good' => 3][$v['level']];
                $cost = $m['cpl'] === null ? '—' : '$'.number_format($m['cpl'], $m['rtype'] === 'traffic' ? 3 : 2);
            @endphp
            <tr class="border-b last:border-0 hover:bg-slate-50 num">
                <td class="py-1.5 pr-3 max-w-xs">
                    <a href="{{ route('ads.show', $ad) }}" class="flex items-center gap-2.5">
                        @if($ad->thumbnail_url)<img src="{{ $ad->thumbnail_url }}" class="w-9 h-9 rounded-lg object-cover shrink-0" loading="lazy" alt="">@else<span class="w-9 h-9 rounded-lg bg-slate-100 shrink-0"></span>@endif
                        <span class="min-w-0">
                            <span class="block font-medium truncate hover:text-indigo-600">{{ $ad->name }}</span>
                            <span class="block text-[11px] text-slate-400 truncate">{{ \App\Services\ResultType::ICON[$m['rtype']] ?? '' }} {{ mb_strtolower($m['rlabel']) }}@if(! $ad->isActive()) · o'chiq @endif</span>
                        </span>
                    </a>
                </td>
                <td class="text-right" data-v="{{ $m['spend'] }}">${{ number_format($m['spend'], 2) }}</td>
                <td class="text-right" data-v="{{ $m['leads'] }}">{{ $m['rtype'] === 'other' ? '—' : number_format($m['leads']) }}</td>
                <td class="text-right font-bold {{ $costColor[$v['level']] }}" data-v="{{ $m['cpl'] }}">{{ $cost }}</td>
                <td class="text-right" data-v="{{ $order }}"><span class="text-[11px] px-2 py-0.5 rounded-full whitespace-nowrap font-semibold {{ $tone[$v['level']] }}">{{ $v['label'] }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@if(! $all && $ads->count() > 30)
    <a href="{{ request()->fullUrlWithQuery(['all' => 1]) }}" class="mt-3 inline-block text-sm text-indigo-600 hover:underline">Barcha {{ $ads->count() }} ta reklamani ko'rsatish</a>
@endif
@endif
