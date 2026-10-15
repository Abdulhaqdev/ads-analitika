<x-layouts.app :title="$title.' · '.$account->name">
    @php
        $q = $range->query();
        $grads = ['from-indigo-500 to-blue-500', 'from-emerald-500 to-teal-500', 'from-orange-500 to-rose-500', 'from-violet-500 to-fuchsia-500', 'from-sky-500 to-cyan-500', 'from-amber-500 to-orange-500'];
        $current = collect($sections)->collapse()[$section];
    @endphp

    <div class="lg:flex lg:gap-6 items-start">
        <aside class="lg:w-60 lg:shrink-0 lg:sticky lg:top-6 mb-5 lg:mb-0">
            <a href="{{ route('dashboard', $q) }}" class="text-sm text-slate-500 hover:text-slate-900">← Barcha akkauntlar</a>

            <div class="bg-white rounded-2xl ring-1 ring-slate-200/70 shadow-sm p-3 lg:p-4 mt-3 lg:block flex items-center gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="w-10 h-10 lg:w-11 lg:h-11 rounded-xl bg-gradient-to-br {{ $grads[$account->id % count($grads)] }} text-white grid place-items-center font-bold shrink-0">{{ mb_strtoupper(mb_substr(trim($account->name), 0, 2)) }}</span>
                    <div class="min-w-0">
                        <p class="font-bold text-slate-900 truncate">{{ $account->name }}</p>
                        <p class="text-[11px] text-slate-400">{{ $account->active_ads ?: '—' }} faol reklama · {{ $account->currency }}</p>
                    </div>
                </div>
                <a href="{{ $account->adsManagerUrl() }}" target="_blank" rel="noopener" class="mt-3 hidden lg:block text-center text-sm font-medium px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white">Ads Manager ↗</a>
                <a href="{{ $account->adsManagerUrl() }}" target="_blank" rel="noopener" class="lg:hidden ml-auto text-xs font-medium px-3 py-1.5 rounded-lg bg-indigo-600 text-white whitespace-nowrap">Ads Manager ↗</a>
            </div>

            <nav class="lg:hidden mt-3 -mx-4 sm:mx-0 px-4 sm:px-0 flex gap-1.5 overflow-x-auto pb-1 snap-x">
                @foreach($sections as $group => $items)
                    @foreach($items as $key => [$label])
                        <a href="{{ route('accounts.show', ['account' => $account, 'section' => $key] + $q) }}"
                           class="snap-start shrink-0 px-3.5 py-2 rounded-xl text-sm font-medium whitespace-nowrap {{ $section === $key ? 'bg-indigo-600 text-white shadow' : 'bg-white ring-1 ring-slate-200 text-slate-600' }}">{{ $label }}</a>
                    @endforeach
                @endforeach
            </nav>

            <nav class="hidden lg:block mt-3 bg-white rounded-2xl ring-1 ring-slate-200/70 shadow-sm p-2 space-y-3">
                @foreach($sections as $group => $items)
                    <div>
                        <p class="px-3 pt-2 pb-1 text-[10px] uppercase tracking-wider text-slate-400 font-bold">{{ $group }}</p>
                        @foreach($items as $key => [$label])
                            <a href="{{ route('accounts.show', ['account' => $account, 'section' => $key] + $q) }}"
                               class="block px-3 py-2 rounded-xl text-sm {{ $section === $key ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                @endforeach
            </nav>
        </aside>

        <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-end gap-4 mb-4">
                <div class="mr-auto">
                    <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">{{ $current[0] }}</h1>
                    <p class="text-sm text-slate-500 mt-0.5">{{ $account->name }} · {{ $range->label() }}@if($current[1]) · {{ $current[1] }}@endif</p>
                </div>
                <x-range-picker :range="$range" />
            </div>

            <div class="bg-white rounded-2xl ring-1 ring-slate-200/70 shadow-sm p-4 sm:p-6">
                {!! $content !!}
            </div>
        </div>
    </div>
</x-layouts.app>
