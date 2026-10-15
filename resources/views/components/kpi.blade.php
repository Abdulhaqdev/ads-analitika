@props(['label', 'value', 'prev' => null, 'lowerIsBetter' => false, 'suffix' => '', 'prefix' => '', 'decimals' => 2, 'hint' => null])

@php
    $delta = ($prev && $value !== null && $prev > 0) ? (($value - $prev) / $prev) * 100 : null;
    $good = $delta === null ? null : ($lowerIsBetter ? $delta <= 0 : $delta >= 0);
    $flat = $delta !== null && abs($delta) < 1;
@endphp
<div class="bg-white rounded-2xl ring-1 ring-slate-200/70 shadow-sm p-3.5" @if($hint) title="{{ $hint }}" @endif>
    <p class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold">{{ $label }}</p>
    <div class="flex items-end justify-between gap-2 mt-1.5">
        <p class="num text-[22px] leading-none font-bold text-slate-900">{{ $value === null ? '—' : $prefix.number_format($value, $decimals).$suffix }}</p>
        @if($delta !== null)
            <span class="num text-[11px] font-semibold px-1.5 py-0.5 rounded-md {{ $flat ? 'bg-slate-100 text-slate-500' : ($good ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700') }}">{{ $delta >= 0 ? '▲' : '▼' }} {{ number_format(abs($delta), 0) }}%</span>
        @endif
    </div>
</div>
