<x-layouts.app title="Akkauntlar · Analitik agentim">
    @php
        $maxSpend = max(1, (float) $cards->max(fn ($c) => $c['m']['spend']));
        $grads = ['from-indigo-500 to-blue-500', 'from-emerald-500 to-teal-500', 'from-orange-500 to-rose-500', 'from-violet-500 to-fuchsia-500', 'from-sky-500 to-cyan-500', 'from-amber-500 to-orange-500'];
        $initials = fn ($n) => mb_strtoupper(mb_substr(trim($n), 0, 2));
    @endphp

    <div class="flex flex-wrap items-end gap-4 mb-5">
        <div class="mr-auto">
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Faol reklama akkauntlari <span class="text-slate-400 font-semibold">{{ $cards->count() }}</span></h1>
            <p class="text-sm text-slate-500 mt-0.5">{{ $range->label() }}@if($range->key === 'today') · bugungi ma'lumot to'liq emas @endif</p>
        </div>
        <x-range-picker :range="$range" />
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <x-kpi label="Jami sarf" :value="$totals['spend']" :prev="$prev['spend']" prefix="$" :decimals="0" />
        @foreach($totals['types'] as $tk => $tv)
            @php $pt = $prev['types'][$tk] ?? null; @endphp
            <div class="bg-white rounded-2xl ring-1 ring-slate-200/70 shadow-sm p-3.5">
                <p class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">{{ \App\Services\ResultType::ICON[$tk] }} {{ \App\Services\ResultType::LABELS[$tk] }}</p>
                <div class="flex items-end justify-between gap-2 mt-1.5">
                    <p class="num text-[22px] leading-none font-bold text-slate-900">{{ number_format($tv['results']) }}</p>
                    <p class="num text-sm font-semibold text-slate-600">${{ $tv['cost'] ?? '—' }} <span class="text-[11px] font-normal text-slate-400">narxi</span></p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-3 mb-3">
        <input id="search" type="search" placeholder="🔎 Akkaunt qidirish…" class="ring-1 ring-slate-200 rounded-xl px-3.5 py-2 text-sm bg-white w-56 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
        <select id="sort" class="ring-1 ring-slate-200 rounded-xl px-3 py-2 text-sm bg-white shadow-sm">
            <option value="spend">Sarf bo'yicha</option><option value="leads">Lid bo'yicha</option><option value="cpl">Lid narxi (arzon avval)</option><option value="cplDesc">Lid narxi (qimmat avval)</option><option value="alerts">Ogohlantirish bo'yicha</option><option value="name">Nomi bo'yicha</option>
        </select>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" id="onlyAlerts" class="w-4 h-4 rounded"> Faqat muammolilar</label>
        <span class="ml-auto text-xs text-slate-400">Akkauntni bosing — alohida sahifada to'liq tahlil ochiladi</span>
    </div>

    @if($cards->isEmpty())
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 text-sm text-amber-900">Faol akkaunt topilmadi. Yangi akkaunt faollashsa, u avtomatik qo'shiladi (har 3 soatda tekshiriladi). Hozir tekshirish: <code>php artisan facebook:discover</code></div>
    @endif

    <div class="hidden lg:grid lg:grid-cols-[minmax(11rem,1fr)_minmax(5rem,9rem)_4.5rem_5.5rem_7.5rem] gap-3 px-5 pb-1.5 text-[11px] uppercase tracking-wide text-slate-400 font-semibold">
        <span>Akkaunt</span><span>Sarf</span><span class="text-right">Natija</span><span class="text-right">Narxi</span><span class="text-right">Holat</span>
    </div>

    <div id="list" class="space-y-2">
        @foreach($cards as $c)
            @php
                $a = $c['account']; $m = $c['m']; $pv = $c['prev'];
                $cplDelta = ($m['cpl'] && $pv['cpl']) ? ($m['cpl'] / $pv['cpl'] - 1) * 100 : null;
                $cfg = config('analytics');
                $target = ($m['rtype'] === 'leads' && $a->target_cpl) ? (float) $a->target_cpl : null;
                if ($m['spend'] <= 0 || $m['cpl'] === null) { $health = 'idle'; }
                elseif ($m['rtype'] === 'traffic') { $health = $m['cpl'] <= $cfg['traffic_cpc_good'] ? 'good' : ($m['cpl'] <= $cfg['traffic_cpc_bad'] ? 'warn' : 'bad'); }
                elseif ($m['rtype'] === 'messages' && $cfg['message_cost_good']) { $health = $m['cpl'] <= $cfg['message_cost_good'] ? 'good' : ($m['cpl'] <= $cfg['message_cost_bad'] ? 'warn' : 'bad'); }
                elseif ($target) { $health = $m['cpl'] <= $target ? 'good' : ($m['cpl'] <= $target * 1.2 ? 'warn' : 'bad'); }
                elseif ($cplDelta !== null) { $health = $cplDelta <= -5 ? 'good' : ($cplDelta >= 15 ? 'bad' : 'warn'); }
                else { $health = 'idle'; }
                $accent = ['good' => 'border-l-emerald-500', 'warn' => 'border-l-amber-400', 'bad' => 'border-l-red-500', 'idle' => 'border-l-slate-300'][$health];
                $cplClass = ['good' => 'text-emerald-600', 'warn' => 'text-amber-600', 'bad' => 'text-red-600', 'idle' => 'text-slate-900'][$health];
                $barPct = $m['spend'] > 0 ? max(3, round($m['spend'] / $maxSpend * 100)) : 0;
                $chip = $c['alerts']
                    ? ['bg-red-50 text-red-700 ring-red-100', '⚠ '.$c['alerts'].' ta muammo']
                    : ($health === 'bad' ? ['bg-red-50 text-red-700 ring-red-100', 'Qimmat'] : ($health === 'good' ? ['bg-emerald-50 text-emerald-700 ring-emerald-100', '✓ Yaxshi'] : ($health === 'warn' ? ['bg-amber-50 text-amber-700 ring-amber-100', "O'rtacha"] : ['bg-slate-50 text-slate-400 ring-slate-100', '—'])));
                $costFmt = $m['rtype'] === 'traffic' ? number_format($m['cpl'] ?? 0, 3) : number_format($m['cpl'] ?? 0, 2);
            @endphp
            <a href="{{ route('accounts.show', ['account' => $a] + $range->query()) }}"
               class="acc block bg-white rounded-xl ring-1 ring-slate-200/70 shadow-sm hover:shadow-md hover:ring-indigo-200 transition border-l-4 {{ $accent }}"
               data-name="{{ mb_strtolower($a->name) }}" data-spend="{{ $m['spend'] }}" data-leads="{{ $m['leads'] }}" data-cpl="{{ $m['cpl'] ?? 999999 }}" data-alerts="{{ $c['alerts'] }}">
                <div class="grid grid-cols-3 lg:grid-cols-[minmax(11rem,1fr)_minmax(5rem,9rem)_4.5rem_5.5rem_7.5rem] gap-x-3 gap-y-2 items-center px-4 py-3">
                    <div class="min-w-0 col-span-3 lg:col-span-1 flex items-center gap-3">
                        <span class="w-9 h-9 rounded-lg bg-gradient-to-br {{ $grads[$a->id % count($grads)] }} text-white grid place-items-center text-xs font-bold shrink-0">{{ $initials($a->name) }}</span>
                        <div class="min-w-0">
                            <p class="font-bold text-slate-900 truncate">{{ $a->name }}</p>
                            <p class="text-[11px] text-slate-400 truncate">{{ $a->active_ads ?: '—' }} faol reklama @foreach($m['types'] as $tk => $tv)@if($tk !== $m['rtype'] && $tv['results'] > 0) · {{ \App\Services\ResultType::ICON[$tk] }} {{ number_format($tv['results']) }} ${{ $tv['cost'] }}@endif @endforeach</p>
                        </div>
                    </div>
                    <div class="col-span-3 lg:col-span-1">
                        @if($m['spend'] > 0)
                            <div class="flex items-center gap-2.5">
                                <div class="h-2.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><div class="h-2.5 rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" style="width: {{ $barPct }}%"></div></div>
                                <span class="num text-sm font-bold w-14 text-right">${{ number_format($m['spend'], 0) }}</span>
                            </div>
                        @else
                            <span class="text-xs text-slate-400">Bu davrda sarf yo'q</span>
                        @endif
                    </div>
                    <div class="lg:text-right num"><span class="lg:hidden text-[10px] uppercase text-slate-400 block">{{ $m['rlabel'] }}</span><b class="text-sm">{{ $m['leads'] ? number_format($m['leads']) : '—' }}</b><span class="hidden lg:block text-[10px] text-slate-400">{{ \App\Services\ResultType::ICON[$m['rtype']] ?? '' }} {{ mb_strtolower($m['rlabel']) }}</span></div>
                    <div class="lg:text-right num font-bold {{ $cplClass }}"><span class="lg:hidden text-[10px] uppercase text-slate-400 block font-normal">Narxi</span><span class="text-sm">{{ $m['cpl'] ? '$'.$costFmt : '—' }}</span>
                        @if($cplDelta !== null && abs($cplDelta) >= 5)<span class="block text-[10px] font-semibold {{ $cplDelta > 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $cplDelta > 0 ? '▲' : '▼' }} {{ number_format(abs($cplDelta), 0) }}%</span>@endif</div>
                    <div class="lg:text-right"><span class="inline-block text-[11px] px-2 py-1 rounded-lg ring-1 font-semibold whitespace-nowrap {{ $chip[0] }}">{{ $chip[1] }}</span></div>
                </div>
            </a>
        @endforeach
    </div>

    @if($inactive->isNotEmpty())
        <details class="mt-6 bg-white rounded-2xl ring-1 ring-slate-200/70 shadow-sm">
            <summary class="cursor-pointer px-5 py-3.5 flex items-center justify-between text-sm font-semibold text-slate-600 select-none">
                <span>Boshqa akkauntlar <span class="text-slate-400 font-normal">({{ $inactive->count() }}) — hozir faol emas</span></span>
                <span class="text-slate-400 text-xs">ko'rsatish ▾</span>
            </summary>
            <div class="px-5 pb-4 divide-y divide-slate-100">
                @foreach($inactive as $a)
                    <div class="flex items-center gap-3 py-2.5 text-sm">
                        <span class="w-2 h-2 rounded-full bg-slate-300"></span>
                        <span class="font-medium text-slate-700 truncate">{{ $a->name }}</span>
                        <span class="text-xs text-slate-400 truncate">{{ $a->statusLabel() }} · 30 kunda sarf ${{ number_format($a->spend_30d, 0) }}</span>
                        <form action="{{ route('facebook.accounts.update', $a) }}" method="POST" class="ml-auto">
                            @csrf @method('PATCH')
                            <input type="hidden" name="is_tracked" value="1">
                            <button class="text-xs px-2.5 py-1 rounded-lg ring-1 ring-slate-200 text-indigo-600 hover:bg-indigo-50 font-medium whitespace-nowrap">+ Kuzatishga qo'shish</button>
                        </form>
                    </div>
                @endforeach
                <p class="pt-3 text-[11px] text-slate-400">Akkaunt faollashsa (reklama yoqilsa yoki sarf bo'lsa), avtomatik asosiy ro'yxatga o'tadi.</p>
            </div>
        </details>
    @endif

    <script>
        const list = document.getElementById('list');
        const accs = [...list.querySelectorAll('.acc')];
        function refresh() {
            const q = document.getElementById('search').value.trim().toLowerCase();
            const only = document.getElementById('onlyAlerts').checked;
            const sort = document.getElementById('sort').value;
            const key = { spend: a => -a.dataset.spend, leads: a => -a.dataset.leads, cpl: a => +a.dataset.cpl, cplDesc: a => -(+a.dataset.cpl === 999999 ? -1 : a.dataset.cpl), alerts: a => -a.dataset.alerts };
            [...accs].sort((a, b) => sort === 'name' ? a.dataset.name.localeCompare(b.dataset.name) : key[sort](a) - key[sort](b)).forEach(a => list.appendChild(a));
            accs.forEach(a => a.classList.toggle('hidden', (q && !a.dataset.name.includes(q)) || (only && +a.dataset.alerts === 0)));
        }
        ['search', 'sort', 'onlyAlerts'].forEach(id => document.getElementById(id).addEventListener('input', refresh));
        refresh();
    </script>
</x-layouts.app>
