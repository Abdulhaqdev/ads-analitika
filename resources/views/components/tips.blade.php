@props(['recs'])
@if(collect($recs)->isNotEmpty())
<ul class="divide-y divide-slate-100 rounded-xl ring-1 ring-slate-200 bg-white overflow-hidden">
    @foreach($recs as $r)
        @php $good = $r['level'] === 'good'; @endphp
        <li>
            <details class="group">
                <summary class="flex items-center gap-2.5 px-3.5 py-2.5 cursor-pointer hover:bg-slate-50 text-sm">
                    <span class="w-2 h-2 rounded-full shrink-0 {{ $good ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                    <span class="flex-1 {{ $good ? 'text-emerald-900' : 'text-slate-800' }} font-medium">{{ $r['title'] }}</span>
                    <span class="text-slate-300 text-xs group-open:rotate-180 transition">▾</span>
                </summary>
                <p class="px-3.5 pb-3 pl-[30px] text-[13px] text-slate-500 leading-snug">{{ $r['detail'] }}</p>
            </details>
        </li>
    @endforeach
</ul>
@endif
