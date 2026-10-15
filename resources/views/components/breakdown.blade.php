@props(['title', 'rows', 'hint' => null, 'minLeads' => 3])

@php
    $rows = collect($rows);
    $maxSpend = max(1, (float) $rows->max('spend'));
    $eligible = $rows->filter(fn ($r) => $r['cpl'] !== null && $r['leads'] >= $minLeads);
    $best = $eligible->min('cpl');
    $worst = $eligible->count() > 1 ? $eligible->max('cpl') : null;
@endphp
<div class="bg-white rounded-lg shadow-sm p-4">
    <h3 class="font-semibold">{{ $title }}</h3>
    @if($hint)<p class="text-xs text-slate-500 mb-2">{{ $hint }}</p>@endif
    @if($rows->isEmpty())
        <p class="text-sm text-slate-400 mt-2">Ma'lumot yo'q. Akkauntni kuzatishga qo'ying va yuklashni kuting.</p>
    @else
        <div class="overflow-x-auto mt-2">
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-500 text-left border-b">
                    <tr><th class="py-1.5">&nbsp;</th><th class="text-right">Sarf</th><th class="text-right">Lid</th><th class="text-right">Lid narxi</th><th class="text-right">CTR</th><th class="w-24">&nbsp;</th></tr>
                </thead>
                <tbody>
                @foreach($rows as $r)
                    @php
                        $isBest = $best !== null && $r['cpl'] !== null && $r['leads'] >= $minLeads && $r['cpl'] == $best;
                        $isWorst = $worst !== null && $r['cpl'] !== null && $r['leads'] >= $minLeads && $r['cpl'] == $worst;
                    @endphp
                    <tr class="border-b last:border-0 {{ $isBest ? 'bg-emerald-50' : ($isWorst ? 'bg-red-50' : '') }}">
                        <td class="py-1.5 pr-2">{{ $r['label'] }}</td>
                        <td class="text-right">{{ number_format($r['spend'], 2) }}</td>
                        <td class="text-right">{{ $r['leads'] }}</td>
                        <td class="text-right font-medium {{ $isBest ? 'text-emerald-700' : ($isWorst ? 'text-red-700' : '') }}">{{ $r['cpl'] !== null ? number_format($r['cpl'], 2) : '—' }}</td>
                        <td class="text-right">{{ $r['ctr'] !== null ? $r['ctr'].'%' : '—' }}</td>
                        <td class="pl-3"><div class="h-1.5 bg-slate-100 rounded"><div class="h-1.5 bg-blue-500 rounded" style="width: {{ round($r['spend'] / $maxSpend * 100) }}%"></div></div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-slate-400 mt-2">Yashil: eng arzon lid, qizil: eng qimmat (kamida {{ $minLeads }} ta lid bo'lganda).</p>
    @endif
</div>
