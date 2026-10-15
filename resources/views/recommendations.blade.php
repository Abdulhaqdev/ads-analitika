<x-layouts.app title="Xatolar va tavsiyalar · Analitik agentim">
    @php
        $tabs = ['all' => ['Hammasi', null], 'bad' => ['Tuzatish kerak', $badTotal], 'good' => ['Kuchaytirish kerak', $goodTotal]];
        $computed = $snapshots->max('computed_at');
    @endphp

    <div class="flex flex-wrap items-end gap-4 mb-5">
        <div class="mr-auto">
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Xatolar va tavsiyalar</h1>
            <p class="text-sm text-slate-500 mt-0.5">Hamma faol akkauntlar bo'yicha, eng muhimi tepada @if($computed) · yangilangan {{ $computed->diffForHumans() }}@endif</p>
        </div>
        <div class="flex bg-white ring-1 ring-slate-200 rounded-xl p-0.5 text-sm shadow-sm">
            @foreach($tabs as $key => [$label, $count])
                <a href="?f={{ $key }}" class="px-3.5 py-1.5 rounded-lg font-medium {{ $filter === $key ? 'bg-slate-900 text-white shadow' : 'text-slate-600 hover:bg-slate-100' }}">
                    @if($key === 'bad')🔴 @elseif($key === 'good')🟢 @endif{{ $label }}@if($count !== null) <span class="opacity-70">{{ $count }}</span>@endif
                </a>
            @endforeach
        </div>
    </div>

    @if($snapshots->isEmpty())
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 text-sm text-amber-900">Hisobot hali tayyor emas: <code>php artisan analytics:reports</code></div>
    @endif

    @foreach($snapshots as $s)
        @php
            $recs = collect($s->payload['recs'] ?? [])->when($filter !== 'all', fn ($c) => $c->where('level', $filter))->values();
            $acCards = collect($cards[$s->facebook_ad_account_id] ?? [])->when($filter === 'bad', fn ($c) => $c->where('kind', '!=', 'good'))->when($filter === 'good', fn ($c) => $c->where('kind', 'good'))->values();
            $t = $s->payload['t'] ?? [];
        @endphp
        @continue($recs->isEmpty() && $acCards->isEmpty())
        <section class="mb-7">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-2.5">
                <a href="{{ route('accounts.show', [$s->account, 'recommendations']) }}" class="text-lg font-bold text-slate-900 hover:text-indigo-600">{{ $s->account->name }}</a>
                <span class="text-xs text-slate-500 num">${{ number_format($t['spend'] ?? 0, 0) }} sarf · {{ $t['leads'] ?? 0 }} lid · CPL {{ isset($t['cpl']) ? '$'.$t['cpl'] : '—' }}</span>
                <a href="{{ route('accounts.show', [$s->account, 'recommendations']) }}" class="ml-auto text-sm text-indigo-600 font-medium">Batafsil →</a>
            </div>
            @if($acCards->isNotEmpty())
                <div class="grid xl:grid-cols-2 gap-2 mb-3">
                    @foreach($acCards->take(4) as $card)<x-ad-card :card="$card" />@endforeach
                </div>
                @if($acCards->count() > 4)<p class="text-xs text-slate-400 mb-3">Yana {{ $acCards->count() - 4 }} ta reklama — <a class="text-indigo-600" href="{{ route('alerts') }}">Ogohlantirishlar</a> sahifasida</p>@endif
            @endif
            <x-tips :recs="$recs->take(6)" />
        </section>
    @endforeach
</x-layouts.app>
