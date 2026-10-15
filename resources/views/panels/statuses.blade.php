@php
    $ads = $report['ads'];
    $count = fn (string $l) => $ads->filter(fn ($r) => $r['verdict']['level'] === $l)->count();
    $noLead = $ads->filter(fn ($r) => $r['verdict']['level'] === 'bad' && $r['m']['leads'] === 0)->count();
    $pill = ['good' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'warn' => 'bg-amber-100 text-amber-800 border-amber-200', 'bad' => 'bg-red-100 text-red-800 border-red-200', 'idle' => 'bg-slate-100 text-slate-500 border-slate-200'];
    $pillText = ['good' => 'Yaxshi', 'warn' => "O'rtacha", 'bad' => 'Yomon', 'idle' => "Ma'lumot yo'q"];
    $card = ['good' => 'border-emerald-200 bg-emerald-50/60', 'warn' => 'border-amber-200 bg-amber-50/60', 'bad' => 'border-red-200 bg-red-50/60', 'idle' => 'border-slate-200 bg-white'];
@endphp

@if($t['spend'] <= 0)
    <p class="text-sm text-slate-400 py-10 text-center">Bu davrda sarf yo'q yoki ma'lumot hali yuklanmagan.</p>
@else
{{-- 2. HOLATLAR --}}
<section class="mb-8">
    <h3 class="flex items-center gap-2 font-semibold text-slate-900 mb-3">Holatlar</h3>
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2.5">
        @foreach($report['statuses'] as $s)
            <div class="rounded-xl border p-3 {{ $card[$s['level']] }}">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-xs text-slate-500">{{ $s['label'] }}</p>
                    <span class="text-[10px] px-1.5 py-0.5 rounded-full border {{ $pill[$s['level']] }} whitespace-nowrap">{{ $pillText[$s['level']] }}</span>
                </div>
                <p class="num text-lg font-semibold mt-1">{{ $s['value'] }}</p>
                <p class="text-[11px] text-slate-400">{{ $s['norm'] }}</p>
            </div>
        @endforeach
    </div>
    <div class="flex flex-wrap gap-2 mt-3 text-xs">
        <span class="px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800">🟢 Yaxshi reklamalar: <b>{{ $count('good') }}</b></span>
        <span class="px-2.5 py-1 rounded-full bg-slate-100 text-slate-700">⚪ O'rtacha: <b>{{ $count('ok') }}</b></span>
        <span class="px-2.5 py-1 rounded-full bg-red-100 text-red-800">🔴 Qimmat: <b>{{ $count('bad') - $noLead }}</b></span>
        <span class="px-2.5 py-1 rounded-full bg-red-100 text-red-800">⛔ Lid yo'q: <b>{{ $noLead }}</b></span>
        <span class="px-2.5 py-1 rounded-full bg-slate-50 text-slate-400">Ma'lumot kam: <b>{{ $count('idle') }}</b></span>
    </div>
</section>


@endif
