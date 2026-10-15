@php
    $money = fn ($v) => $v === null ? '—' : '$'.number_format((float) $v, 2);
    $label = fn ($type) => ['leads' => 'lid', 'messages' => 'xabar', 'traffic' => 'klik', 'landing' => 'landing view'][$type] ?? 'natija';
    $base = $range->query();
    $best = $products->filter(fn ($p) => $p['results'] >= 10 && $p['cost'] !== null)->min('cost');
@endphp

@php
    $field = 'ring-1 ring-slate-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400';
    $step = 'text-[11px] uppercase tracking-wide text-slate-400 font-semibold mb-1';
    // what the plan was built with, carried to the "prepare" button so it creates exactly what is shown
    $settings = array_filter([
        'a' => $account->id,
        'p' => $product,
        'adsets' => $opts['adsets'],
        'videos' => $opts['videos'],
        'budget' => request('budget'),
        'structure' => $opts['structure'],
        'split' => $opts['split'] ? 1 : null,
        'link' => $opts['dest'] === 'site' ? $opts['link'] : null,
        'dest' => $opts['dest'],
        'form' => $opts['dest'] === 'form' ? ($plan['form'] ?? $opts['form']) : null,
        'r' => request('r'),
        'from' => request('r') === 'custom' ? request('from') : null,
        'to' => request('r') === 'custom' ? request('to') : null,
    ], fn ($v) => $v !== null && $v !== '');
@endphp

<form method="GET" action="{{ url()->current() }}" class="mb-5 rounded-2xl ring-1 ring-slate-200 bg-white p-4 sm:p-5">
    @foreach($base as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    <input type="hidden" name="a" value="{{ $account->id }}">
    <h3 class="font-bold text-slate-900 mb-3">Reklama sozlamalari</h3>
    <div class="grid sm:grid-cols-2 gap-3">
        <div>
            <p class="{{ $step }}">1. Reklama kabineti</p>
            <select class="{{ $field }} w-full" onchange="location.href = this.value">
                @foreach($accounts as $a)
                    <option value="{{ route('launch', ['a' => $a->id] + request()->except('a', 'page')) }}" @selected($a->id === $account->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <p class="{{ $step }}">2. Mahsulot</p>
            <input type="text" name="p" value="{{ $product }}" list="launch-products" placeholder="masalan: bolalar palatka" class="{{ $field }} w-full" autocomplete="off">
            <datalist id="launch-products">@foreach($products as $p)<option value="{{ $p['key'] }}">@endforeach</datalist>
        </div>
        <div>
            <p class="{{ $step }}">3. Byudjet turi</p>
            <div class="flex gap-2">
                @foreach(['cbo' => ['CBO', 'kampaniya byudjeti'], 'abo' => ['ABO', 'har guruhga byudjet']] as $v => [$t, $hint])
                    <label class="flex-1 cursor-pointer rounded-xl ring-1 px-3 py-2 text-sm has-[:checked]:ring-2 has-[:checked]:ring-indigo-500 has-[:checked]:bg-indigo-50 ring-slate-200">
                        <input type="radio" name="structure" value="{{ $v }}" class="sr-only" @checked($opts['structure'] === $v)>
                        <b>{{ $t }}</b> <span class="text-xs text-slate-500">· {{ $hint }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <div>
            <p class="{{ $step }}">4. Kunlik byudjet, $</p>
            <input type="number" name="budget" min="1" step="1" value="{{ request('budget') }}" placeholder="avto: CBO 20$, ABO o'tgan o'rtacha" class="{{ $field }} w-full">
        </div>
        <div class="sm:col-span-2">
            <p class="{{ $step }}">5. Qayerga olib boradi</p>
            <div class="flex gap-2 mb-2">
                @foreach(['site' => ['Sayt', 'landingga, pixel Lead'], 'form' => ['Lid forma', 'Facebook ichidagi forma']] as $v => [$t, $hint])
                    <label class="flex-1 cursor-pointer rounded-xl ring-1 px-3 py-2 text-sm has-[:checked]:ring-2 has-[:checked]:ring-indigo-500 has-[:checked]:bg-indigo-50 ring-slate-200">
                        <input type="radio" name="dest" value="{{ $v }}" class="sr-only" @checked($opts['dest'] === $v)
                            onchange="document.getElementById('dest-site').hidden = this.value !== 'site'; document.getElementById('dest-form').hidden = this.value !== 'form';">
                        <b>{{ $t }}</b> <span class="text-xs text-slate-500">· {{ $hint }}</span>
                    </label>
                @endforeach
            </div>
            <div id="dest-site" @if($opts['dest'] !== 'site') hidden @endif>
                <input type="url" name="link" value="{{ $opts['link'] }}" placeholder="sayt havolasi; bo'sh qolsa — reklamalardagi oldingi havola" class="{{ $field }} w-full">
            </div>
            <div id="dest-form" @if($opts['dest'] !== 'form') hidden @endif>
                <input type="text" name="form" value="{{ $opts['form'] }}" inputmode="numeric" placeholder="forma ID; bo'sh qolsa — shu mahsulotda eng ko'p lid bergan forma" class="{{ $field }} w-full">
                @if(! empty($plan['forms']))
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        @foreach($plan['forms'] as $f)
                            <a href="{{ url()->current() }}?{{ http_build_query(['form' => $f['id']] + $settings + $base) }}" class="text-xs px-2.5 py-1 rounded-full ring-1 {{ ($plan['form'] ?? null) === $f['id'] ? 'bg-indigo-50 ring-indigo-300 text-indigo-700 font-semibold' : 'ring-slate-200 text-slate-600 hover:bg-slate-50' }}">{{ $f['name'] ?? 'forma '.$f['id'] }} · @if($f['results']){{ $f['results'] }} lid · {{ $f['videos'] }} video @else sahifadagi forma @endif</a>
                        @endforeach
                    </div>
                @endif
                <p class="text-[11px] text-slate-400 mt-1">Avval shu mahsulotda lid bergan formalar, keyin sahifadagi boshqa faol formalar. Nomlar ko'rinmasa — Sozlamalarda Facebook'ni qayta ulang (sahifa ruxsati).</p>
            </div>
        </div>
        <div class="sm:col-span-2 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-slate-600">
            <label class="flex items-center gap-1.5">Guruhlar <input type="number" name="adsets" min="1" max="8" value="{{ $opts['adsets'] }}" class="w-14 ring-1 ring-slate-200 rounded-lg px-2 py-1.5 text-sm"></label>
            <label class="flex items-center gap-1.5">Har guruhga video <input type="number" name="videos" min="1" max="8" value="{{ $opts['videos'] }}" class="w-14 ring-1 ring-slate-200 rounded-lg px-2 py-1.5 text-sm"></label>
            <label class="flex items-center gap-1.5"><input type="checkbox" name="split" value="1" @checked($opts['split']) class="rounded"> Har video alohida kampaniya</label>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-3 mt-4">
        <button class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold whitespace-nowrap">1-qadam: rejani ko'rish</button>
        <p class="text-[11px] text-slate-400">Reja hali hech narsa yaratmaydi. Tekshirib bo'lgach pastdagi yashil "2-qadam" tugmasi kampaniyani Ads Manager'da tayyorlaydi. Tahlil {{ $range->label() }} natijalari bo'yicha.</p>
    </div>
</form>

@if($product === '')
    <h3 class="font-bold text-slate-900 mb-2">Mahsulotlar reytingi</h3>
    <p class="text-xs text-slate-500 mb-3">Yashil: {{ $label('leads') }} narxi eng arzon (kamida 10 ta natija). Mahsulot ustiga bosing — reja tuziladi.</p>
    @if($products->isEmpty())
        <p class="text-sm text-slate-400 py-8 text-center">Bu davrda sarf bo'lgan kampaniya yo'q. Davrni kengaytiring.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-[11px] uppercase tracking-wide text-slate-400 text-left border-b">
                    <tr><th class="py-1.5">Mahsulot</th><th class="text-right">Sarf</th><th class="text-right">Natija</th><th class="text-right">Narxi</th><th class="text-right">Faol reklama</th><th></th></tr>
                </thead>
                <tbody>
                @foreach($products as $p)
                    @php $good = $best && $p['cost'] !== null && $p['results'] >= 10 && $p['cost'] <= $best * 1.15; @endphp
                    <tr class="border-b last:border-0 num hover:bg-slate-50">
                        <td class="py-2 pr-3 max-w-xs truncate font-medium">{{ $p['key'] }} <span class="text-[11px] text-slate-400">· {{ $p['campaigns'] }} kamp.</span></td>
                        <td class="text-right">${{ number_format($p['spend'], 0) }}</td>
                        <td class="text-right">{{ $p['results'] }} <span class="text-[11px] text-slate-400">{{ $label($p['type']) }}</span></td>
                        <td class="text-right font-bold {{ $good ? 'text-emerald-600' : '' }}">{{ $money($p['cost']) }}</td>
                        <td class="text-right">@if($p['active'])<span class="text-emerald-600">● {{ $p['active'] }}</span>@else<span class="text-slate-300">—</span>@endif</td>
                        <td class="text-right"><a href="{{ url()->current() }}?{{ http_build_query(['p' => $p['key']] + $settings + $base) }}" class="text-xs px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 font-semibold hover:bg-indigo-100 whitespace-nowrap">Reja →</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@elseif(! $plan)
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-900">"{{ $product }}" nomli kampaniya {{ $range->label() }} davrida topilmadi. Nomning boshqa qismini yozing yoki davrni kengaytiring.</div>
    <a href="{{ url()->current() }}?{{ http_build_query(['a' => $account->id] + $base) }}" class="inline-block mt-3 text-sm text-indigo-600">← Mahsulotlar reytingi</a>
@else
    <a href="{{ url()->current() }}?{{ http_build_query(['a' => $account->id] + $base) }}" class="text-sm text-slate-500 hover:text-slate-900">← Mahsulotlar reytingi</a>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 my-3">
        <x-kpi label="O'tgan sarf" :value="$plan['spend']" prefix="$" />
        <x-kpi :label="ucfirst($label($plan['type'])).' soni'" :value="$plan['results']" :decimals="0" />
        <x-kpi :label="ucfirst($label($plan['type'])).' narxi'" :value="$plan['cost']" prefix="$" />
        <div class="bg-white rounded-2xl ring-1 ring-slate-200/70 shadow-sm p-3.5"><p class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Asos</p><p class="mt-1.5 text-sm"><b>{{ $plan['campaigns'] }}</b> kampaniya · {{ $plan['range'] }}</p></div>
    </div>

    @if($plan['error'])<p class="text-xs text-amber-700 mb-3">Yosh/jins tahlili olinmadi: {{ $plan['error'] }}</p>@endif

    @if($result)
        @if($result['ok'])
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 mb-4 text-sm text-emerald-900">
                ✅ Ads Manager'da tayyor: <b>{{ $result['name'] }}</b> · {{ count($result['campaigns'] ?? [1]) }} kampaniya, {{ $result['adsets'] }} guruh, {{ $result['ads'] }} reklama. Hammasi <b>to'xtatilgan</b> — tekshirib, o'zingiz yoqing.
                <a href="https://adsmanager.facebook.com/adsmanager/manage/{{ count($result['campaigns'] ?? []) > 1 ? 'campaigns' : 'adsets' }}?act={{ $account->account_id }}&selected_campaign_ids={{ implode(',', $result['campaigns'] ?? [$result['campaign_id']]) }}" target="_blank" rel="noopener" class="ml-1 font-semibold underline">Ads Manager'da ochish ↗</a>
            </div>
            @if($result['warning'] ?? null)
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 mb-4 text-sm text-amber-900">{{ $result['warning'] }}</div>
            @endif
        @else
            <div class="rounded-xl border border-red-300 bg-red-50 p-4 mb-4 text-sm text-red-900">
                Tayyorlab bo'lmadi: {{ $result['error'] }}
                @if($result['campaign_id'] ?? null)<br>Qisman yaratilgan kampaniya (to'xtatilgan): <a href="https://adsmanager.facebook.com/adsmanager/manage/adsets?act={{ $account->account_id }}&selected_campaign_ids={{ $result['campaign_id'] }}" target="_blank" rel="noopener" class="underline">ochish ↗</a> — keraksiz bo'lsa o'chiring.@endif
            </div>
        @endif
    @endif

    @php $ready = $canWrite && $plan['adsets'] && $plan['creatives'] && ($plan['dest'] !== 'form' || $plan['form']); @endphp
    {{-- one form for both "prepare" buttons; the ticked groups and videos below join it through form="launch-form" --}}
    <form id="launch-form" method="POST" action="{{ route('accounts.launch', $account) }}" class="hidden"
        onsubmit="return launchSubmit(this)">
        @csrf
        @foreach($settings as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    </form>
    <script>
        const picked = (name) => document.querySelectorAll(`input[form=launch-form][name="${name}[]"]:checked`).length;
        function launchSubmit(form) {
            if (!picked('pick_adsets')) { alert('Kamida bitta guruh belgilang'); return false; }
            if (!picked('pick_videos')) { alert('Kamida bitta video belgilang'); return false; }
            document.querySelectorAll('button[form=launch-form]').forEach(b => { b.disabled = true; b.textContent = 'Tayyorlanmoqda… sahifani yopmang'; });
            return true;
        }
        document.addEventListener('change', (e) => {
            if (e.target.form?.id !== 'launch-form') return;
            document.querySelectorAll('[data-count]').forEach(el => { el.textContent = picked(el.dataset.count); });
        });
    </script>

    {{-- step 2 right under the numbers, so it is not lost below the long plan --}}
    @if(! ($result['ok'] ?? false))
        <div class="rounded-2xl border-2 border-emerald-300 bg-emerald-50 p-4 mb-4 flex flex-wrap items-center gap-3">
            <div class="mr-auto text-sm text-emerald-900">
                <b>2-qadam:</b> pastda kerakli guruh va videolarni belgilang (eng yaxshilari belgilangan) —
                <b><span data-count="pick_adsets">{{ count($plan['adsets']) }}</span> guruh, <span data-count="pick_videos">{{ count($plan['creatives']) }}</span> video</b>.
                Tugmani bosing, kampaniya Ads Manager'da <b>to'xtatilgan</b> holda yaratiladi (1–3 daqiqa).
            </div>
            @if($ready)
                <button form="launch-form" class="text-sm px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">2-qadam: Ads Manager'da tayyorlash</button>
            @elseif(! $canWrite)
                <a href="{{ route('facebook.index') }}" class="text-sm px-4 py-2 rounded-xl bg-amber-100 text-amber-900 font-semibold">Avval Facebook'ni qayta ulang (reklama yaratish ruxsati) →</a>
            @else
                <span class="text-sm text-red-700">Tayyorlab bo'lmaydi: guruh, video{{ $plan['dest'] === 'form' ? ' yoki lid forma' : '' }} topilmadi.</span>
            @endif
        </div>
    @endif

    {{-- the plan --}}
    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-4 sm:p-5 mb-5">
        <div class="flex flex-wrap items-center gap-2 mb-3">
            <h3 class="font-bold text-slate-900 mr-auto">🚀 Reklama rejasi: {{ $plan['product'] }}</h3>
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('plan-text').value).then(() => { this.textContent = 'Nusxalandi ✓'; })" class="text-xs px-3 py-1.5 rounded-lg bg-white ring-1 ring-indigo-200 text-indigo-700 font-semibold">Rejani nusxalash</button>
        </div>
        <div class="grid sm:grid-cols-3 gap-3 text-sm mb-4">
            <div><p class="text-[11px] uppercase text-slate-400 font-semibold">Maqsad</p><p class="font-medium">{{ $plan['objective'] ?? '—' }}</p></div>
            <div><p class="text-[11px] uppercase text-slate-400 font-semibold">Optimallashtirish</p><p class="font-medium">{{ $plan['optimization'] ?? '—' }}@if(isset($plan['promoted']['custom_event_type'])) · {{ $plan['promoted']['custom_event_type'] }}@endif</p></div>
            <div><p class="text-[11px] uppercase text-slate-400 font-semibold">Byudjet</p><p class="font-medium">
                @if($plan['structure'] === 'cbo') CBO · kampaniyaga {{ $money($plan['budget']) }}/kun @else ABO · har guruh {{ $money($plan['budget']) }}/kun @endif
                @if($plan['split']) · {{ count($plan['creatives']) }} ta kampaniya @endif
                · jami {{ $money($plan['daily']) }}/kun</p></div>
        </div>

        <p class="text-[11px] uppercase text-slate-400 font-semibold mb-1.5">Guruhlar (auditoriyalar) — o'tgan natija bo'yicha eng arzondan, belgilanganlari yaratiladi</p>
        @forelse($plan['adsetChoices'] as $i => $a)
            <label class="block cursor-pointer bg-white rounded-xl ring-1 ring-slate-200 p-3 mb-2 has-[:checked]:ring-2 has-[:checked]:ring-emerald-500">
                <div class="flex flex-wrap items-baseline gap-2">
                    <input type="checkbox" form="launch-form" name="pick_adsets[]" value="{{ $a['id'] }}" @checked($a['selected']) class="rounded self-center">
                    <b>{{ $i + 1 }}. {{ $a['label'] }}</b>
                    <span class="text-xs text-emerald-700 font-semibold">{{ $money($a['cost']) }} · {{ $a['results'] }} ta</span>
                    <span class="text-[11px] text-slate-400 truncate">{{ $a['campaign'] }}</span>
                </div>
                <p class="text-xs text-slate-600 mt-1 pl-6">{{ $a['summary'] }}</p>
            </label>
        @empty
            <p class="text-sm text-slate-500">Yetarli natijali adset topilmadi (har birida kamida bir necha natija bo'lishi kerak).</p>
        @endforelse

        <p class="text-[11px] uppercase text-slate-400 font-semibold mt-4 mb-1.5">{{ $plan['split'] ? 'Videolar (har biri alohida kampaniyada, hamma guruhlarda)' : 'Videolar (har bir guruhga hammasi qo\'yiladi)' }} — natija shu videoli barcha reklamalar yig'indisi</p>
        @php
            $vids = collect($plan['videoChoices']);
            $mostLeads = $vids->max('results');
            $cheapest = $vids->where('proven', true)->min('cost');
            $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
        @endphp
        <p class="text-[11px] text-slate-500 mb-1.5">Ustun nomini bosing — saralanadi. Lid narxi yashil: mahsulot o'rtachasidan ({{ $money($plan['cost']) }}) arzon, qizil: 25% dan ko'p qimmat. Hook — 3 soniya ko'rganlar ulushi, ushlab turish — oxirigacha (ThruPlay) ko'rganlar.</p>
        <div class="overflow-x-auto bg-white rounded-xl ring-1 ring-slate-200">
            <table class="w-full text-xs" id="video-table">
                <thead class="text-[10px] uppercase tracking-wide text-slate-400 text-left border-b bg-slate-50">
                    <tr>
                        <th class="p-2 w-8"></th>
                        <th class="p-2">Video</th>
                        @foreach(['results' => 'Lid', 'cost' => 'Lid narxi', 'spend' => 'Sarf', 'ctr' => 'CTR', 'hook' => 'Hook', 'hold' => 'Ushlab turish', 'ads' => 'Reklama'] as $k => $t)
                            <th class="p-2 text-right cursor-pointer select-none hover:text-slate-700" data-sort="{{ $k }}">{{ $t }} ↕</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @foreach($vids as $c)
                    @php $tone = $c['cost'] === null || ! $plan['cost'] ? '' : ($c['cost'] <= $plan['cost'] ? 'text-emerald-700' : ($c['cost'] > $plan['cost'] * 1.25 ? 'text-red-600' : '')); @endphp
                    <tr class="border-b last:border-0 num hover:bg-emerald-50/40 cursor-pointer has-[:checked]:bg-emerald-50"
                        @foreach(['results', 'cost', 'spend', 'ctr', 'hook', 'hold', 'ads'] as $k) data-{{ $k }}="{{ $c[$k] ?? '' }}" @endforeach
                        onclick="if (event.target.tagName !== 'INPUT') { const i = this.querySelector('input'); i.checked = !i.checked; i.dispatchEvent(new Event('change', {bubbles: true})); }">
                        <td class="p-2"><input type="checkbox" form="launch-form" name="pick_videos[]" value="{{ $c['ad_id'] }}" @checked($c['selected']) class="rounded"></td>
                        <td class="p-2">
                            <div class="flex items-center gap-2 min-w-[180px]">
                                @if($c['thumb'])<img src="{{ $c['thumb'] }}" alt="" class="w-10 h-10 rounded-md object-cover shrink-0" loading="lazy">@endif
                                <div class="min-w-0">
                                    <p class="font-semibold truncate max-w-[220px]">{{ $c['is_video'] ? '🎬' : '🖼' }} {{ $c['name'] }}</p>
                                    <p class="flex flex-wrap gap-1 mt-0.5">
                                        @if($c['results'] === $mostLeads && $mostLeads > 0)<span class="px-1.5 rounded bg-indigo-100 text-indigo-700">eng ko'p lid</span>@endif
                                        @if($c['proven'] && $c['cost'] === $cheapest)<span class="px-1.5 rounded bg-emerald-100 text-emerald-700">eng arzon</span>@endif
                                        @if(! $c['proven'])<span class="px-1.5 rounded bg-slate-100 text-slate-500">kam ma'lumot</span>@endif
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="p-2 text-right font-semibold">{{ number_format($c['results']) }}</td>
                        <td class="p-2 text-right font-bold {{ $tone }}">{{ $money($c['cost']) }}</td>
                        <td class="p-2 text-right">{{ $money($c['spend']) }}</td>
                        <td class="p-2 text-right">{{ $pct($c['ctr']) }}</td>
                        <td class="p-2 text-right">{{ $pct($c['hook']) }}</td>
                        <td class="p-2 text-right">{{ $pct($c['hold']) }}</td>
                        <td class="p-2 text-right text-slate-500">{{ $c['ads'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <script>
            document.querySelectorAll('#video-table th[data-sort]').forEach(th => th.addEventListener('click', () => {
                const key = th.dataset.sort, body = th.closest('table').tBodies[0];
                // cost: cheapest first; everything else: biggest first; empty values last
                const dir = th.dataset.dir = key === 'cost' ? (th.dataset.dir === 'asc' ? 'desc' : 'asc') : (th.dataset.dir === 'desc' ? 'asc' : 'desc');
                const val = r => r.dataset[key] === '' ? null : parseFloat(r.dataset[key]);
                [...body.rows].sort((a, b) => {
                    const x = val(a), y = val(b);
                    if (x === null) return 1; if (y === null) return -1;
                    return dir === 'asc' ? x - y : y - x;
                }).forEach(r => body.appendChild(r));
            }));
        </script>
        @if($plan['dest'] === 'form')
            <p class="text-xs text-slate-500 mt-2">Lid forma: <b class="text-slate-700">{{ $plan['form'] ? (collect($plan['forms'])->firstWhere('id', $plan['form'])['name'] ?? $plan['form']) : 'tanlanmagan' }}</b> · optimallashtirish LEAD_GENERATION, Facebook ichida
                @if(! $plan['form'])<span class="text-red-700">· forma ID'sini yozing — bu mahsulotda lid formali reklama topilmadi</span>
                @elseif($plan['formChanged'])<span class="text-amber-700">· ayrim videolar boshqa forma yoki saytga olib borgan, yangi kreativ nusxasi yaratiladi</span>@endif
            </p>
        @elseif($plan['link'])
            <p class="text-xs text-slate-500 mt-2">Havola: <a href="{{ $plan['link'] }}" target="_blank" rel="noopener" class="text-indigo-600 break-all">{{ $plan['link'] }}</a>
                @if($plan['linkChanged'])<span class="text-amber-700">· eski reklamalardagidan farq qiladi, yangi kreativ nusxasi yaratiladi</span>@endif
            </p>
        @endif

        @if($plan['audiences'])
            <p class="text-[11px] uppercase text-slate-400 font-semibold mt-4 mb-1.5">Istisno auditoriyalar (avval ko'rgan / lid qoldirganlar)</p>
            <div class="flex flex-wrap gap-1.5">
                @foreach($plan['audiences'] as $aud)
                    <span class="text-xs px-2.5 py-1 rounded-full {{ $aud['selected'] ? 'bg-red-50 text-red-700 ring-1 ring-red-200' : 'bg-white text-slate-400 ring-1 ring-slate-200' }}">{{ $aud['selected'] ? '⛔ ' : '' }}{{ $aud['name'] }}</span>
                @endforeach
            </div>
            <p class="text-[11px] text-slate-400 mt-1">Qizillari istisno qilinadi: eng yaxshi adsetlarda allaqachon bor yoki 180 kunlik auditoriya.</p>
        @endif

        <div class="flex flex-wrap gap-2 mt-5">
            @if($ready)
                <button form="launch-form" class="text-sm px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">2-qadam: Ads Manager'da tayyorlash (to'xtatilgan)</button>
            @elseif(! $canWrite)
                <a href="{{ route('facebook.index') }}" class="text-sm px-4 py-2 rounded-xl bg-amber-100 text-amber-900 font-semibold">Tayyorlash uchun Facebook'ni qayta ulang (reklama yaratish ruxsati) →</a>
            @endif
            @if($plan['adsetsUrl'])<a href="{{ $plan['adsetsUrl'] }}" target="_blank" rel="noopener" class="text-sm px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">Ads Manager'da adsetlarni ochish ↗</a>@endif
            @if($plan['adsUrl'])<a href="{{ $plan['adsUrl'] }}" target="_blank" rel="noopener" class="text-sm px-4 py-2 rounded-xl bg-white ring-1 ring-indigo-200 text-indigo-700 font-semibold">Kreativlarni ochish ↗</a>@endif
        </div>
        <ol class="text-xs text-slate-600 mt-3 list-decimal list-inside space-y-0.5">
            <li>"Adsetlarni ochish": hammasini belgilang → Dublikat → <b>Yangi kampaniya</b> (tavsiyalar belgisini olib tashlang).</li>
            <li>{{ $plan['structure'] === 'cbo' ? 'Kampaniya byudjetini (CBO) '.$money($plan['budget']) : 'Har bir adsetda byudjetni '.$money($plan['budget']) }} qiling va istisno auditoriyalarni qo'shing.</li>
            <li>Har adsetda faqat yuqoridagi kreativlarni qoldiring, qolganini o'chiring.</li>
            <li>Tekshirib, o'zingiz yoqing.</li>
        </ol>
        <textarea id="plan-text" class="sr-only" readonly>{{ $plan['text'] }}</textarea>
    </div>

    @if($plan['insights'])
        <h3 class="font-bold text-slate-900 mb-2">Tahlil xulosalari</h3>
        <div class="space-y-1.5 mb-5">
            @foreach($plan['insights'] as $in)
                <div class="text-sm rounded-xl px-3 py-2 border {{ ['good' => 'bg-emerald-50 border-emerald-200 text-emerald-900', 'bad' => 'bg-red-50 border-red-200 text-red-900'][$in['level']] ?? 'bg-slate-50 border-slate-200 text-slate-700' }}">{{ $in['text'] }}</div>
            @endforeach
        </div>
    @endif

    @if(! empty($plan['brief']['sections']))
        <h3 class="font-bold text-slate-900 mb-2">Batafsil: yosh, jins, joy va vaqt</h3>
        <div class="grid md:grid-cols-2 gap-x-8 gap-y-4 text-sm">
            @foreach($plan['brief']['sections'] as $s)
                <div>
                    <p class="font-semibold text-slate-800 mb-1">{{ $s['title'] }}</p>
                    <ul class="space-y-0.5 text-slate-600">@foreach($s['lines'] as $l)<li class="{{ str_starts_with($l, '   ') ? 'pl-4 text-xs text-slate-500' : '' }}">{{ trim($l) }}</li>@endforeach</ul>
                </div>
            @endforeach
        </div>
    @endif
@endif
