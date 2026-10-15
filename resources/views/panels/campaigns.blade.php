@php $max = max(1, (float) $campaigns->max(fn ($c) => $c['m']['spend'])); @endphp
@if($campaigns->isEmpty())
    <p class="text-sm text-slate-400 py-10 text-center">Bu davrda sarf yo'q.</p>
@else
<div class="overflow-x-auto">
    <table class="w-full text-sm sortable">
        <thead class="text-[11px] uppercase tracking-wide text-slate-400 text-left border-b">
            <tr><th class="py-2">Kampaniya</th><th data-sort class="text-right">Sarf</th><th data-sort class="text-right">Natija</th><th data-sort class="text-right">Narxi</th></tr>
        </thead>
        <tbody>
        @foreach($campaigns as $c)
            @php $m = $c['m']; @endphp
            <tr class="border-b last:border-0 num">
                <td class="py-2 pr-3 max-w-md">
                    <span class="block font-medium truncate">{{ $c['name'] }}</span>
                    <span class="flex items-center gap-2"><span class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><span class="block h-1.5 rounded-full bg-indigo-400" style="width: {{ round($m['spend'] / $max * 100) }}%"></span></span><span class="text-[11px] text-slate-400 shrink-0">{{ \App\Services\ResultType::ICON[$m['rtype']] ?? '' }} {{ mb_strtolower($m['rlabel']) }}</span></span>
                </td>
                <td class="text-right" data-v="{{ $m['spend'] }}">${{ number_format($m['spend'], 2) }}</td>
                <td class="text-right" data-v="{{ $m['leads'] }}">{{ $m['rtype'] === 'other' ? '—' : number_format($m['leads']) }}</td>
                <td class="text-right font-bold" data-v="{{ $m['cpl'] }}">{{ $m['cpl'] === null ? '—' : '$'.number_format($m['cpl'], $m['rtype'] === 'traffic' ? 3 : 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
