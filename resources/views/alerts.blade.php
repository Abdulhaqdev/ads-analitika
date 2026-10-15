<x-layouts.app title="Ogohlantirishlar · Analitik agentim">
    <div class="mb-5">
        <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Ogohlantirishlar</h1>
        <p class="text-sm text-slate-500 mt-0.5">Qaysi reklama qimmatlashdi va nima qilish kerak @if($date) · {{ $date->format('d.m.Y') }}@endif</p>
    </div>

    @forelse($groups as $account => $items)
        @php $shown = $items->take(6); $rest = $items->slice(6); @endphp
        <section class="mb-6">
            <h2 class="flex items-center gap-2 font-bold text-slate-900 mb-2.5">{{ $account }}
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-red-50 text-red-700">{{ $items->where('kind', '!=', 'good')->count() }} ta muammo</span>
                @if($items->where('kind', 'good')->count())<span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">{{ $items->where('kind', 'good')->count() }} ta yaxshi</span>@endif
            </h2>
            <div class="grid xl:grid-cols-2 gap-2">
                @foreach($shown as $card)<x-ad-card :card="$card" />@endforeach
            </div>
            @if($rest->isNotEmpty())
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm text-indigo-600 font-medium select-none">Yana {{ $rest->count() }} ta ▾</summary>
                    <div class="grid xl:grid-cols-2 gap-2 mt-3">@foreach($rest as $card)<x-ad-card :card="$card" />@endforeach</div>
                </details>
            @endif
        </section>
    @empty
        <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-6 text-emerald-800 text-sm">✅ Hozircha ogohlantirish yo'q.</div>
    @endforelse
</x-layouts.app>
