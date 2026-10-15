@props(['range'])
<div class="flex flex-wrap items-center gap-2" x-data>
    <div class="flex bg-white ring-1 ring-slate-200 rounded-xl p-0.5 text-sm shadow-sm">
        @foreach(\App\Services\DateRange::PRESETS as $key => $label)
            <a href="?r={{ $key }}" class="px-3 py-1.5 rounded-lg font-medium {{ $range->key === (string) $key ? 'bg-slate-900 text-white shadow' : 'text-slate-600 hover:bg-slate-100' }}">{{ $label }}</a>
        @endforeach
    </div>
    <details class="relative">
        <summary class="cursor-pointer px-3.5 py-2 rounded-xl text-sm font-medium ring-1 shadow-sm select-none {{ $range->key === 'custom' ? 'bg-indigo-600 text-white ring-indigo-600' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50' }}">
            📅 {{ $range->key === 'custom' ? $range->label() : 'Sana tanlash' }}
        </summary>
        <form method="GET" class="absolute right-0 z-30 mt-2 w-72 bg-white rounded-2xl shadow-xl ring-1 ring-slate-200 p-4 space-y-3">
            <input type="hidden" name="r" value="custom">
            <label class="block text-xs font-medium text-slate-500">Boshlanish
                <input type="date" name="from" value="{{ $range->from->toDateString() }}" max="{{ today()->toDateString() }}" required class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
            </label>
            <label class="block text-xs font-medium text-slate-500">Tugash
                <input type="date" name="to" value="{{ $range->to->toDateString() }}" max="{{ today()->toDateString() }}" required class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
            </label>
            <button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg py-2">Qo'llash</button>
            <p class="text-[11px] text-slate-400">Ma'lumotlar yuklangan davr uchun (oxirgi ~30 kun). Razbivka (joylashuv, yosh, soat) istalgan sana uchun Facebook'dan olinadi.</p>
        </form>
    </details>
</div>
