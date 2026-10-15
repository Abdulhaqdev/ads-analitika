<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; background: #f4f6fb; }
        .num { font-variant-numeric: tabular-nums; }
        th[data-sort] { cursor: pointer; user-select: none; }
        th[data-sort]:hover { color: #0f172a; }
        details > summary { list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
    </style>
</head>
<body class="text-slate-800 antialiased">
    @php
        $latestAlert = \App\Models\AdAlert::max('date');
        $alertCount = $latestAlert ? \App\Models\AdAlert::whereDate('date', \Illuminate\Support\Carbon::parse($latestAlert)->toDateString())->whereIn('severity', ['critical', 'warning'])->count() : 0;
        $redCount = \App\Models\AccountReportSnapshot::where('days', 7)->get()->sum(fn ($s) => $s->redCount());
        $nav = [
            ['dashboard', 'Akkauntlar', ['/', 'accounts/*', 'ads/*'], 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10', null],
            ['launch', 'Reklama yoqish', ['launch'], 'M5 19l4-1 9-9a2.8 2.8 0 00-4-4l-9 9-1 4zM13 6l5 5M14 4c2-2 5-2 6-1s1 4-1 6', null],
            ['recommendations', 'Xatolar va tavsiyalar', ['recommendations'], 'M12 9v4m0 4h.01M10.3 3.9L2.4 18a2 2 0 001.7 3h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z', $redCount],
            ['alerts', 'Ogohlantirishlar', ['alerts'], 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 11-6 0', $alertCount],
            ['insights', 'Razbivka tahlili', ['insights'], 'M4 20V10m6 10V4m6 16v-7m6 7V8', null],
            ['facebook.index', 'Sozlamalar', ['admin/facebook*'], 'M12 15a3 3 0 100-6 3 3 0 000 6zm7.4-3a7.4 7.4 0 00-.1-1.2l2-1.6-2-3.4-2.4 1a7.6 7.6 0 00-2-1.2L14.5 3h-4l-.4 2.6a7.6 7.6 0 00-2 1.2l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 000 2.4l-2 1.6 2 3.4 2.4-1c.6.5 1.3.9 2 1.2l.4 2.6h4l.4-2.6c.7-.3 1.4-.7 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2z', null],
        ];
    @endphp

    <div class="lg:flex min-h-screen">
        <aside class="lg:w-60 lg:shrink-0 bg-slate-900 text-slate-300 lg:min-h-screen lg:sticky lg:top-0 lg:h-screen flex lg:flex-col">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 px-5 py-4 lg:py-6">
                <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-400 via-blue-500 to-cyan-400 grid place-items-center text-white font-extrabold shadow-lg shadow-indigo-900/40">A</span>
                <span class="hidden sm:block leading-tight"><span class="block font-bold text-white">{{ config('app.name') }}</span><span class="block text-[11px] text-slate-400">Reklama tahlili</span></span>
            </a>
            <nav class="flex lg:flex-col gap-1 px-2 lg:px-3 flex-1 items-center lg:items-stretch overflow-x-auto [scrollbar-width:none]">
                @foreach($nav as [$route, $label, $patterns, $icon, $badge])
                    <a href="{{ route($route) }}" class="flex items-center gap-3 px-3 py-2 rounded-xl text-sm whitespace-nowrap {{ request()->is(...$patterns) ? 'bg-white/10 text-white font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                        <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>
                        <span class="hidden sm:inline lg:flex-1 whitespace-nowrap">{{ $label }}</span>
                        @if($badge)<span class="hidden sm:inline text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-red-500 text-white">{{ $badge }}</span>@endif
                    </a>
                @endforeach
            </nav>
            <form method="POST" action="{{ route('logout') }}" class="p-3 lg:p-4 lg:border-t lg:border-white/10">
                @csrf
                <button class="w-full text-left text-sm text-slate-400 hover:text-white px-3 py-2 rounded-xl hover:bg-white/5">Chiqish</button>
            </form>
        </aside>

        <main class="flex-1 min-w-0 px-4 sm:px-6 lg:px-8 py-6">
            @if(session('status'))
                <div class="mb-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">{{ session('status') }}</div>
            @endif
            {{ $slot }}
        </main>
    </div>

    <script>
        window.initCharts = (root = document) => root.querySelectorAll('canvas[data-chart]').forEach(c => {
            if (c._chart) return;
            try {
                const cfg = JSON.parse(c.dataset.chart);
                Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
                Chart.defaults.color = '#64748b';
                c._chart = new Chart(c, cfg);
            } catch (e) { console.error(e); }
        });
        document.addEventListener('DOMContentLoaded', () => window.initCharts());
        document.addEventListener('click', e => {
            const th = e.target.closest('table.sortable th[data-sort]');
            if (!th) return;
            const table = th.closest('table');
            const col = [...th.parentNode.children].indexOf(th);
            const dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
            th.parentNode.querySelectorAll('th').forEach(h => delete h.dataset.dir);
            th.dataset.dir = dir;
            const body = table.tBodies[0];
            const num = r => { const v = parseFloat(r.cells[col].dataset.v); return isNaN(v) ? (dir === 'asc' ? Infinity : -Infinity) : v; };
            [...body.rows].sort((a, b) => dir === 'asc' ? num(a) - num(b) : num(b) - num(a)).forEach(r => body.appendChild(r));
        });
    </script>
</body>
</html>
