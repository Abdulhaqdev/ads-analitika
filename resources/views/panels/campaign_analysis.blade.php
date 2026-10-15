@php
    $cur = $account->currency === 'USD' ? '$' : '';
    $label = ['leads' => 'Lid', 'messages' => 'Xabar', 'traffic' => 'Klik', 'landing' => 'Landing view'][$data['type'] ?? 'leads'] ?? 'Natija';
    $tone = ['good' => 'bg-emerald-50 border-emerald-300 text-emerald-900', 'bad' => 'bg-red-50 border-red-300 text-red-900', 'idle' => 'bg-slate-50 border-slate-200 text-slate-700'];
    $icon = ['good' => '🟢', 'bad' => '🔴', 'idle' => '▫️'];
@endphp

<form method="GET" action="{{ url()->current() }}" class="mb-4">
    @foreach($range->query() as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Kampaniya nomi (bir qismini yozing, bir nechta bo'lsa vergul bilan)</label>
    <div class="flex gap-2">
        <input type="text" name="kw" value="{{ implode(', ', $keywords) }}" placeholder="masalan: ultra sonic, asvijitel" class="flex-1 min-w-0 ring-1 ring-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400" autocomplete="off">
        <button class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold whitespace-nowrap">Tahlil qilish</button>
    </div>
    @if($families->isNotEmpty())
        <div class="flex flex-wrap gap-1.5 mt-2.5">
            <span class="text-[11px] text-slate-400 self-center mr-1">Tez tanlash:</span>
            @foreach($families->take(14) as $f)
                <a href="{{ url()->current() }}?{{ http_build_query($range->query() + ['kw' => $f['key']]) }}" class="text-xs px-2.5 py-1 rounded-full ring-1 ring-slate-200 bg-white hover:bg-indigo-50 hover:ring-indigo-200 text-slate-700">{{ $f['key'] }} <span class="text-slate-400">${{ number_format($f['spend'], 0) }}</span></a>
            @endforeach
        </div>
    @endif
</form>

@if(! $data)
    <p class="text-sm text-slate-400 py-10 text-center">Kampaniya nomini yozing yoki yuqoridagi tugmalardan birini bosing.</p>
@elseif($data['campaigns']->isEmpty())
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-900">"{{ implode(', ', $keywords) }}" nomli kampaniya bu davrda topilmadi (yoki sarfi yo'q). Boshqa so'z yoki kengroq davr sinab ko'ring.</div>
@else
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
        <x-kpi label="Sarf" :value="$data['spend']" :prefix="$cur" />
        <x-kpi :label="$label.' soni'" :value="$data['results']" :decimals="0" />
        <x-kpi :label="$label.' narxi'" :value="$data['cost']" :prefix="$cur" />
        <div class="bg-white rounded-2xl ring-1 ring-slate-200/70 shadow-sm p-3.5"><p class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Topildi</p><p class="mt-1.5 text-sm"><b>{{ $data['campaigns']->count() }}</b> kampaniya · <b>{{ $data['ads']->count() }}</b> reklama</p></div>
    </div>
    <div class="overflow-x-auto mb-5">
        <table class="w-full text-sm">
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 text-left border-b"><tr><th class="py-1.5">Kampaniya</th><th class="text-right">Sarf</th><th class="text-right">{{ $label }}</th><th class="text-right">Narxi</th></tr></thead>
            <tbody>
            @php $minCost = $data['campaigns']->filter(fn ($c) => $c['m']['leads'] >= 5 && $c['m']['cpl'] !== null)->min(fn ($c) => $c['m']['cpl']); @endphp
            @foreach($data['campaigns']->sortBy(fn ($c) => $c['m']['cpl'] ?? 9999)->take(10) as $c)
                <tr class="border-b last:border-0 num">
                    <td class="py-1.5 pr-3 max-w-xs truncate">{{ $c['name'] }}</td>
                    <td class="text-right">${{ number_format($c['m']['spend'], 0) }}</td>
                    <td class="text-right">{{ $c['m']['leads'] }}</td>
                    <td class="text-right font-bold {{ ($minCost && $c['m']['cpl'] !== null && $c['m']['leads'] >= 5 && $c['m']['cpl'] <= $minCost) ? 'text-emerald-600' : '' }}">{{ $c['m']['cpl'] !== null ? '$'.number_format($c['m']['cpl'], 2) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($data['campaigns']->count() > 10)<p class="text-[11px] text-slate-400 mt-1">Yana {{ $data['campaigns']->count() - 10 }} ta kampaniya hisobga olingan.</p>@endif
    </div>

    @if(! empty($data['brief']['sections']))
        <div class="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-4 sm:p-5 mb-6">
            <div class="flex items-center justify-between gap-3 mb-3">
                <h3 class="font-bold text-slate-900">🚀 Yangi reklama uchun brief</h3>
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('brief-text').value).then(() => { this.textContent = 'Nusxalandi ✓'; })" class="text-xs px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold whitespace-nowrap">Nusxalash</button>
            </div>
            <div class="grid md:grid-cols-2 gap-x-8 gap-y-4 text-sm">
                @foreach($data['brief']['sections'] as $sec)
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-indigo-700 font-bold mb-1">{{ $sec['title'] }}</p>
                        <ul class="space-y-1">
                            @foreach($sec['lines'] as $line)
                                @if(str_starts_with($line, '   '))<li class="text-xs text-slate-500 pl-3 leading-snug">{{ trim($line) }}</li>
                                @else<li class="text-slate-800 leading-snug">• {{ $line }}</li>@endif
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            <textarea id="brief-text" class="hidden" readonly>{{ $data['brief']['text'] }}</textarea>
            <p class="text-[11px] text-slate-400 mt-3">Namuna kichik bo'lsa ({{ $data['results'] }} ta natija), bu taxminiy yo'nalish, kafolat emas.</p>
        </div>
    @endif

    @if($data['insights'])
        <h3 class="font-bold text-slate-900 mb-2">Xulosa</h3>
        <div class="space-y-1.5 mb-6">
            @foreach($data['insights'] as $i)
                <div class="rounded-lg border px-3 py-2 text-sm {{ $tone[$i['level']] }}">{{ $icon[$i['level']] }} {{ $i['text'] }}</div>
            @endforeach
        </div>
    @endif

    @if($data['error'])<p class="text-sm text-red-600 mb-4">Yosh/jins ma'lumotini olib bo'lmadi: {{ $data['error'] }}</p>@endif

    @if($data['ageGender']->isNotEmpty())
        <div class="grid lg:grid-cols-2 gap-5 mb-6">
            <div>
                <h3 class="font-bold text-slate-900 mb-2">Jins</h3>
                @include('panels.breakdown', ['title' => '', 'hint' => null, 'rows' => $data['genders'], 'total' => $data['genders']->count(), 'chart' => null])
            </div>
            <div>
                <h3 class="font-bold text-slate-900 mb-2">Yosh</h3>
                @include('panels.breakdown', ['title' => '', 'hint' => null, 'rows' => $data['ages'], 'total' => $data['ages']->count(), 'chart' => null])
            </div>
        </div>
        <h3 class="font-bold text-slate-900 mb-2">Yosh va jins birga</h3>
        @include('panels.breakdown', ['title' => '', 'hint' => "Yashil: eng arzon, qizil: eng qimmat (kamida 3 ta natija bo'lganda)", 'rows' => $data['ageGender']->sortByDesc('spend')->values(), 'total' => $data['ageGender']->count(), 'chart' => $chart])
    @endif

    @foreach(['placement' => 'Joylashuv', 'hour' => 'Soatlar', 'device' => 'Qurilma', 'region' => 'Hudud'] as $kind => $title)
        @php $rows = $data['extra'][$kind] ?? collect(); @endphp
        @if($rows->isNotEmpty())
            <h3 class="font-bold text-slate-900 mt-8 mb-2">{{ $title }}</h3>
            @if($rows->sum('leads') === 0)
                <p class="text-sm text-slate-400">Facebook bu bo'lim uchun natija sonini bermadi (sarf bor, lekin lidlar hududga bog'lanmagan), shuning uchun xulosa chiqarilmadi.</p>
            @else
                @include('panels.breakdown', ['title' => '', 'hint' => null, 'rows' => $kind === 'hour' ? $rows : $rows->sortByDesc('spend')->values(), 'total' => $rows->count(), 'chart' => null])
            @endif
        @endif
    @endforeach

    <h3 class="font-bold text-slate-900 mt-8 mb-2">Auditoriya (adset)</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 text-left border-b"><tr><th class="py-2">Adset va sozlamasi</th><th class="text-right">Sarf</th><th class="text-right">{{ $label }}</th><th class="text-right">Narxi</th></tr></thead>
            <tbody>
            @foreach($data['audiences']->take(12) as $a)
                @php $c = ['good' => 'text-emerald-600', 'bad' => 'text-red-600', 'ok' => 'text-slate-900', 'idle' => 'text-slate-500'][$a['level']]; @endphp
                <tr class="border-b last:border-0 num">
                    <td class="py-2 pr-3 max-w-md"><span class="block font-medium truncate">{{ $a['name'] }}</span><span class="block text-[11px] text-slate-400 truncate">{{ $a['targeting'] }} · {{ $a['ads'] }} reklama</span></td>
                    <td class="text-right">${{ number_format($a['spend'], 0) }}</td>
                    <td class="text-right">{{ $a['results'] }}</td>
                    <td class="text-right font-bold {{ $c }}">{{ $a['cost'] !== null ? '$'.number_format($a['cost'], 2) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="text-[11px] text-slate-400 mt-1">Narx rangi faqat kamida 30 ta natijali adsetlarda ko'rsatiladi.</p>

    @if($data['bestVideos']->isNotEmpty())
        <h3 class="font-bold text-slate-900 mt-8 mb-2">🟢 Eng arzon videolar <span class="text-slate-400 font-normal text-sm">kamida 20 ta natija</span></h3>
        <div class="grid xl:grid-cols-2 gap-2">@foreach($data['bestVideos'] as $row)<x-video-card :row="$row" />@endforeach</div>
    @endif
    @if($data['worstVideos']->isNotEmpty())
        <h3 class="font-bold text-slate-900 mt-6 mb-2">🔴 Eng qimmat videolar <span class="text-slate-400 font-normal text-sm">o'chirishni o'ylang</span></h3>
        <div class="grid xl:grid-cols-2 gap-2">@foreach($data['worstVideos'] as $row)<x-video-card :row="$row" />@endforeach</div>
    @endif
@endif
