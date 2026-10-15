@if($t['spend'] <= 0)
    <p class="text-sm text-slate-400 py-10 text-center">Bu davrda sarf yo'q yoki ma'lumot hali yuklanmagan.</p>
@else
    @php
        $problems = $cards->where('kind', '!=', 'good')->values();
        $winners = $cards->where('kind', 'good')->values();
        $bad = collect($report['recs'])->where('level', 'bad')->values();
        $good = collect($report['recs'])->where('level', 'good')->values();
    @endphp

    @if($problems->isNotEmpty())
        <h3 class="font-bold text-slate-900 mb-2.5">🔴 Qimmatlashgan reklamalar <span class="text-slate-400 font-normal">{{ $problems->count() }}</span></h3>
        <div class="grid xl:grid-cols-2 gap-2 mb-2.5">
            @foreach($problems->take(8) as $card)<x-ad-card :card="$card" />@endforeach
        </div>
        @if($problems->count() > 8)
            <details class="mb-2.5"><summary class="cursor-pointer text-sm text-indigo-600 font-medium select-none">Yana {{ $problems->count() - 8 }} ta ▾</summary>
                <div class="grid xl:grid-cols-2 gap-2 mt-3">@foreach($problems->slice(8) as $card)<x-ad-card :card="$card" />@endforeach</div></details>
        @endif
    @endif

    @if($bad->isNotEmpty())
        <h3 class="font-bold text-slate-900 mt-6 mb-2.5">🔴 Tuzatish kerak</h3>
        <x-tips :recs="$bad" />
    @endif

    @if($winners->isNotEmpty())
        <h3 class="font-bold text-slate-900 mt-7 mb-2.5">🟢 Yaxshi ishlayotgan reklamalar <span class="text-slate-400 font-normal">{{ $winners->count() }}</span></h3>
        <div class="grid xl:grid-cols-2 gap-2">
            @foreach($winners->take(6) as $card)<x-ad-card :card="$card" />@endforeach
        </div>
    @endif

    @if($good->isNotEmpty())
        <h3 class="font-bold text-slate-900 mt-6 mb-2.5">🟢 Kuchaytirish kerak</h3>
        <x-tips :recs="$good" />
    @endif

    @if($problems->isEmpty() && $bad->isEmpty() && $winners->isEmpty() && $good->isEmpty())
        <p class="text-sm text-slate-400 py-8 text-center">Hozircha tavsiya yo'q.</p>
    @endif
@endif
