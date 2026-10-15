<?php

namespace App\Http\Controllers;

use App\Models\FacebookAdAccount;
use App\Models\FacebookConnection;
use App\Services\FacebookGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FacebookController extends Controller
{
    public function __construct(private FacebookGraph $graph) {}

    public function index(): View
    {
        return view('facebook.index', [
            'configured' => $this->graph->isConfigured(),
            'redirectUri' => $this->graph->redirectUri(),
            'connections' => FacebookConnection::with('adAccounts')->latest()->get(),
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->graph->isConfigured()) {
            return redirect()->route('facebook.index')->withErrors(['facebook' => 'FACEBOOK_APP_ID va FACEBOOK_APP_SECRET .env faylda kiritilmagan.']);
        }

        $state = Str::random(40);
        $request->session()->put('facebook_oauth_state', $state);

        return redirect()->away($this->graph->loginUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $back = redirect()->route('facebook.index');
        $expected = $request->session()->pull('facebook_oauth_state');

        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->withErrors(['facebook' => 'Facebook ulanishi bekor qilindi yoki xatolik: '.$request->input('error_description', $request->input('error', 'noma\'lum'))]);
        }

        if (! $expected || ! hash_equals($expected, (string) $request->input('state'))) {
            return $back->withErrors(['facebook' => 'Xavfsizlik tekshiruvi (state) o\'tmadi. Qayta urinib ko\'ring.']);
        }

        try {
            $token = $this->graph->exchangeCode($request->string('code')->toString());
            $me = $this->graph->me($token['access_token']);

            $connection = FacebookConnection::updateOrCreate(
                ['facebook_user_id' => $me['id']],
                [
                    'name' => $me['name'] ?? null,
                    'access_token' => $token['access_token'],
                    'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds($token['expires_in']) : null,
                ],
            );

            $count = $this->syncAdAccounts($connection);
        } catch (\Throwable $e) {
            report($e);

            return $back->withErrors(['facebook' => 'Facebook bilan ulanishda xatolik: '.$e->getMessage()]);
        }

        return $back->with('status', "Facebook ulandi: {$connection->name}. {$count} ta reklama akkaunti topildi.");
    }

    public function sync(FacebookConnection $connection): RedirectResponse
    {
        try {
            $count = $this->syncAdAccounts($connection);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['facebook' => 'Sinxronlashda xatolik: '.$e->getMessage()]);
        }

        return back()->with('status', "{$count} ta reklama akkaunti yangilandi.");
    }

    public function updateAccount(Request $request, FacebookAdAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'is_tracked' => ['sometimes', 'boolean'],
            'target_cpl' => ['nullable', 'numeric', 'min:0'],
        ]);

        $account->update([
            'is_tracked' => $request->boolean('is_tracked'),
            'pinned' => $request->boolean('is_tracked'),
        ] + ($request->has('target_cpl') ? ['target_cpl' => $data['target_cpl'] ?? null] : []));

        return back()->with('status', "{$account->name} sozlamalari saqlandi.");
    }

    public function syncAccount(FacebookAdAccount $account): RedirectResponse
    {
        abort_unless($account->is_tracked, 422, 'Avval akkauntni kuzatishga qo\'ying.');

        dispatch(fn () => Artisan::call('facebook:sync', ['--account' => $account->account_id, '--days' => 30]))->afterResponse();

        return back()->with('status', "{$account->name}: yuklash boshlandi. 1–3 daqiqadan keyin sahifani yangilang.");
    }

    public function destroy(FacebookConnection $connection): RedirectResponse
    {
        $connection->delete();

        return redirect()->route('facebook.index')->with('status', 'Facebook ulanishi uzildi.');
    }

    private function syncAdAccounts(FacebookConnection $connection): int
    {
        $accounts = $this->graph->adAccounts($connection->access_token);

        foreach ($accounts as $account) {
            $connection->adAccounts()->updateOrCreate(
                ['account_id' => $account['account_id']],
                [
                    'name' => $account['name'] ?? null,
                    'currency' => $account['currency'] ?? null,
                    'timezone_name' => $account['timezone_name'] ?? null,
                    'account_status' => $account['account_status'] ?? null,
                ],
            );
        }

        $connection->forceFill(['last_synced_at' => now()])->save();

        return count($accounts);
    }
}
