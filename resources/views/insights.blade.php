<x-layouts.app title="Razbivka tahlili · Analitik agentim">
    @php
        $genderName = fn ($g) => ['male' => 'Erkak', 'female' => 'Ayol', 'unknown' => "Noma'lum"][$g] ?? $g;
        $add = fn ($rows, $fn) => collect($rows)->map(fn ($r) => ['label' => $fn($r)] + $r)->all();
        $known = fn ($r) => strtolower($r['age'] ?? '') !== 'unknown' && strtolower($r['gender'] ?? '') !== 'unknown';
        $best = fn ($rows, $min = 3) => collect($rows)->filter(fn ($r) => $known($r) && $r['cpl'] !== null && $r['leads'] >= $min)->sortBy('cpl')->first();
        $worst = fn ($rows, $min = 3) => collect($rows)->filter(fn ($r) => $known($r) && $r['cpl'] !== null && $r['leads'] >= $min)->sortByDesc('cpl')->first();

        $hourRows = $add($hours, fn ($r) => sprintf('%02d:00 – %02d:59', $r['hour'], $r['hour']));
        $ageRows = $add($ages, fn ($r) => $r['age']);
        $genderRows = $add($genders, fn ($r) => $genderName($r['gender']));
        $comboRows = $add($ageGender->sortByDesc('spend'), fn ($r) => $genderName($r['gender']).', '.$r['age']);
        $dayRows = $add($weekdays, fn ($r) => $r['label']);
        $placementRows = $add($placements, fn ($r) => ucfirst($r['platform'] ?? '?').' · '.str_replace('_', ' ', $r['position'] ?? '?'));
        $deviceRows = $add($devices, fn ($r) => str_replace('_', ' ', $r['device'] ?? '?'));
        $regionRows = $add($regions, fn ($r) => $r['region'] ?? '?');
    @endphp

    <form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
        <h1 class="text-xl font-semibold mr-auto">Razbivka tahlili <span class="text-sm font-normal text-slate-500">oxirgi 30 kun</span></h1>
        <select name="account" onchange="this.form.submit()" class="border border-slate-300 rounded-md px-2 py-1.5 text-sm bg-white">
            <option value="">Barcha akkauntlar</option>
            @foreach($accounts as $a)
                <option value="{{ $a->id }}" @selected($accountId === $a->id)>{{ $a->name }}</option>
            @endforeach
        </select>
    </form>

    @php
        $facts = [];
        if ($b = $best($hourRows)) { $facts[] = "Eng arzon lid soati: {$b['label']} (lid narxi ".number_format($b['cpl'], 2).')'; }
        if ($w = $worst($hourRows)) { $facts[] = "Eng qimmat soat: {$w['label']} (".number_format($w['cpl'], 2).')'; }
        if ($b = $best($genderRows)) { $facts[] = "Jins bo'yicha eng arzon lid: {$b['label']} (".number_format($b['cpl'], 2).')'; }
        if ($b = $best($ageRows)) { $facts[] = "Yosh bo'yicha eng arzon lid: {$b['label']} (".number_format($b['cpl'], 2).')'; }
        if ($w = $worst($ageRows)) { $facts[] = "Eng qimmat yosh guruhi: {$w['label']} (".number_format($w['cpl'], 2).')'; }
        if ($b = $best($dayRows, 5)) { $facts[] = "Eng yaxshi hafta kuni: {$b['label']} (".number_format($b['cpl'], 2).')'; }
        if ($w = $worst($dayRows, 5)) { $facts[] = "Eng zaif hafta kuni: {$w['label']} (".number_format($w['cpl'], 2).')'; }
        if ($b = $best($placementRows)) { $facts[] = "Eng yaxshi joylashuv: {$b['label']} (".number_format($b['cpl'], 2).')'; }
        if ($w = $worst($placementRows)) { $facts[] = "Eng qimmat joylashuv: {$w['label']} (".number_format($w['cpl'], 2).") — undagi sarfni kamaytirishni o'ylab ko'ring"; }
        if ($b = $best($regionRows)) { $facts[] = "Eng arzon hudud: {$b['label']} (".number_format($b['cpl'], 2).')'; }
        if ($w = $worst($regionRows)) { $facts[] = "Eng qimmat hudud: {$w['label']} (".number_format($w['cpl'], 2).')'; }
    @endphp

    @if($facts)
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-5 text-sm">
            <p class="font-semibold mb-1">Asosiy xulosalar</p>
            <ul class="list-disc pl-5 space-y-0.5">@foreach($facts as $f)<li>{{ $f }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-4">
        <div class="lg:col-span-2 bg-white rounded-lg shadow-sm p-4">
            <h3 class="font-semibold">Soatlar bo'yicha (reklama akkaunti vaqti)</h3>
            <p class="text-xs text-slate-500 mb-2">Ustunlar: lidlar soni. Chiziq: lid narxi. Qaysi soatda arzon lid kelishini ko'ring.</p>
            <div class="h-56"><canvas id="hours"></canvas></div>
        </div>
        <x-breakdown title="Soatlar jadvali" :rows="$hourRows" :min-leads="2" />
        <x-breakdown title="Hafta kunlari" :rows="$dayRows" :min-leads="5" hint="Qaysi kunlarda reklama yaxshi ishlaydi" />
        <x-breakdown title="Jins" :rows="$genderRows" />
        <x-breakdown title="Yosh" :rows="$ageRows" />
        <div class="lg:col-span-2"><x-breakdown title="Jins va yosh birga" :rows="$comboRows" :min-leads="2" hint="Qaysi guruhdan lid arzon kelishini ko'rsatadi" /></div>
        <x-breakdown title="Joylashuv (placement)" :rows="$placementRows" hint="Facebook/Instagram lentasi, Stories, Reels va boshqalar" />
        <x-breakdown title="Qurilma" :rows="$deviceRows" />
        <div class="lg:col-span-2"><x-breakdown title="Hudud (viloyat)" :rows="$regionRows" :min-leads="2" /></div>
    </div>

    <script>
        const hourData = @json(collect($hourRows));
        new Chart(document.getElementById('hours'), {
            data: {
                labels: hourData.map(r => String(r.hour).padStart(2, '0') + ':00'),
                datasets: [
                    { type: 'bar', label: 'Lidlar', data: hourData.map(r => r.leads), backgroundColor: '#86efac', yAxisID: 'y' },
                    { type: 'line', label: 'Lid narxi', data: hourData.map(r => r.cpl), borderColor: '#dc2626', backgroundColor: '#dc2626', tension: .3, spanGaps: true, yAxisID: 'y1' },
                ],
            },
            options: { maintainAspectRatio: false, scales: { y1: { position: 'right', grid: { drawOnChartArea: false } } } },
        });
    </script>
</x-layouts.app>
