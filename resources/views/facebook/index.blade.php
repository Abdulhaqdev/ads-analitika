<x-layouts.app title="Akkauntlar · Analitik agentim">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">Facebook akkauntlari</h1>
        @if($configured)
            <a href="{{ route('facebook.redirect') }}" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-md">Facebook orqali ulash</a>
        @endif
    </div>

    @if($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">{{ $errors->first() }}</div>
    @endif

    @unless($configured)
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-5 text-sm text-amber-900 space-y-2">
            <p class="font-medium">Avval Meta ilovasi kalitlarini kiriting</p>
            <p><code>.env</code> fayliga <code>FACEBOOK_APP_ID</code> va <code>FACEBOOK_APP_SECRET</code> qo'shing.</p>
            <input type="text" readonly value="{{ $redirectUri }}" class="w-full bg-white border-amber-200 rounded-md text-sm" onclick="this.select()">
        </div>
    @endunless

    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm mb-4">
        Tahlil qilmoqchi bo'lgan akkauntlarni <b>"Kuzatish"</b> ga qo'ying, <b>maqsadli lid narxi</b>ni yozing (ogohlantirishlar shunga qarab ishlaydi) va <b>Saqlash</b> ni bosing. So'ng <b>Yuklash</b> tugmasi bilan statistikani oling. Keyin har 3 soatda avtomatik yangilanadi.
    </div>

    <div class="space-y-4">
        @forelse($connections as $connection)
            <div class="bg-white rounded-lg shadow-sm p-4">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <p class="font-medium">{{ $connection->name }}</p>
                        <p class="text-xs text-slate-500">Token: {{ $connection->token_expires_at ? $connection->token_expires_at->format('d.m.Y').' gacha' : 'muddatsiz' }} · {{ $connection->adAccounts->count() }} ta akkaunt</p>
                    </div>
                    <div class="flex items-center gap-3 text-sm">
                        <form action="{{ route('facebook.sync', $connection) }}" method="POST">@csrf<button class="text-blue-600 hover:underline">Ro'yxatni yangilash</button></form>
                        <form action="{{ route('facebook.destroy', $connection) }}" method="POST" onsubmit="return confirm('Ulanishni uzishni tasdiqlaysizmi?');">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Uzish</button></form>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-slate-500 border-b">
                            <tr><th class="py-2">Akkaunt</th><th>Holat</th><th>Valyuta</th><th>Kuzatish</th><th>Maqsadli CPL</th><th>Oxirgi yuklash</th><th></th></tr>
                        </thead>
                        <tbody>
                        @foreach($connection->adAccounts->sortByDesc('is_tracked') as $account)
                            <tr class="border-b last:border-0">
                                <form action="{{ route('facebook.accounts.update', $account) }}" method="POST">
                                    @csrf @method('PATCH')
                                    <td class="py-2">{{ $account->name }}<span class="block text-xs text-slate-400">act_{{ $account->account_id }}</span></td>
                                    <td>{{ $account->statusLabel() }}</td>
                                    <td>{{ $account->currency }}</td>
                                    <td><input type="checkbox" name="is_tracked" value="1" @checked($account->is_tracked) class="w-4 h-4"></td>
                                    <td><input type="number" step="0.01" min="0" name="target_cpl" value="{{ $account->target_cpl }}" placeholder="masalan 1.50" class="w-28 border border-slate-300 rounded px-2 py-1 text-sm"></td>
                                    <td class="text-xs text-slate-500">{{ $account->last_synced_at?->diffForHumans() ?? '—' }}</td>
                                    <td class="whitespace-nowrap">
                                        <button class="text-blue-600 hover:underline">Saqlash</button>
                                </form>
                                @if($account->is_tracked)
                                    <form action="{{ route('facebook.accounts.sync', $account) }}" method="POST" class="inline ml-3">@csrf<button class="text-emerald-700 hover:underline">Yuklash</button></form>
                                @endif
                                    </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            @if($configured)<p class="text-slate-500">Hozircha Facebook ulanmagan.</p>@endif
        @endforelse
    </div>
</x-layouts.app>
