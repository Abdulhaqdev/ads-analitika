@if($t['spend'] <= 0)
    <p class="text-sm text-slate-400 py-10 text-center">Bu davrda sarf yo'q yoki ma'lumot hali yuklanmagan.</p>
@else
{{-- 3. SABABLAR --}}
<section class="mb-8">
    <h3 class="flex items-center gap-2 font-semibold text-slate-900 mb-1">Sabablar</h3>
    <p class="text-xs text-slate-400 mb-3">Oldingi {{ $days }} kunga nisbatan nima o'zgardi va nima uchun.</p>
    <div class="space-y-2">
        @foreach($report['reasons'] as $r)
            @php $st = ['bad' => 'border-red-300 bg-red-50 text-red-900', 'good' => 'border-emerald-300 bg-emerald-50 text-emerald-900', 'idle' => 'border-slate-200 bg-slate-50 text-slate-700'][$r['level']]; $ic = ['bad' => '🔴', 'good' => '🟢', 'idle' => '•'][$r['level']]; @endphp
            <div class="border-l-4 rounded-r-lg px-3 py-2 text-sm {{ $st }}">{{ $ic }} {{ $r['text'] }}</div>
        @endforeach
    </div>

</section>


@endif
