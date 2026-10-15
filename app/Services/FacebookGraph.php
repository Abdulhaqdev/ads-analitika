<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class FacebookGraph
{
    /**
     * ads_management is used only by "Reklama yoqish": campaigns are created paused, never switched on.
     * The page permissions only read lead form names there (a page's forms need a page token).
     */
    public const SCOPES = ['ads_read', 'ads_management', 'pages_show_list', 'pages_read_engagement', 'pages_manage_ads'];

    public function isConfigured(): bool
    {
        return filled(config('services.facebook.app_id')) && filled(config('services.facebook.app_secret'));
    }

    public function loginUrl(string $state): string
    {
        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.facebook.app_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(',', self::SCOPES),
        ]);
    }

    public function redirectUri(): string
    {
        return config('services.facebook.redirect_uri') ?: route('facebook.callback');
    }

    /** @return array{access_token: string, expires_in?: int} */
    public function exchangeCode(string $code): array
    {
        $short = $this->client()->get('oauth/access_token', [
            'client_id' => config('services.facebook.app_id'),
            'client_secret' => config('services.facebook.app_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ])->throw()->json();

        return $this->client()->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.app_id'),
            'client_secret' => config('services.facebook.app_secret'),
            'fb_exchange_token' => $short['access_token'],
        ])->throw()->json();
    }

    /** @return array{id: string, name: string} */
    public function me(string $token): array
    {
        return $this->client()->get('me', ['fields' => 'id,name', 'access_token' => $token])->throw()->json();
    }

    /** @return list<array<string, mixed>> */
    public function adAccounts(string $token): array
    {
        $accounts = [];
        $response = $this->client()->get('me/adaccounts', [
            'fields' => 'account_id,name,currency,timezone_name,account_status',
            'limit' => 100,
            'access_token' => $token,
        ])->throw()->json();

        while (true) {
            $accounts = array_merge($accounts, $response['data'] ?? []);
            $next = $response['paging']['next'] ?? null;

            if (! $next) {
                break;
            }

            $response = Http::timeout(20)->get($next)->throw()->json();
        }

        return $accounts;
    }

    /**
     * Fetch every page of a Graph API edge, retrying on rate limits.
     *
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function paginate(string $path, array $params, string $token): array
    {
        $rows = [];
        $params['access_token'] = $token;
        $params['limit'] ??= 200;
        $response = $this->request(fn () => $this->client()->get($path, $params));

        while (true) {
            $rows = array_merge($rows, $response['data'] ?? []);
            $next = $response['paging']['next'] ?? null;

            if (! $next) {
                break;
            }

            $response = $this->request(fn () => Http::timeout(120)->get($next));
        }

        return $rows;
    }

    /**
     * Single Graph API GET.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function get(string $path, array $params, string $token): array
    {
        $params['access_token'] = $token;

        return $this->request(fn () => $this->client()->get($path, $params));
    }

    /**
     * Graph API batch: up to 50 GET sub-requests in one call.
     *
     * @param  list<string>  $relativeUrls
     * @return list<array<string, mixed>> decoded bodies, failed sub-requests skipped
     */
    public function batch(array $relativeUrls, string $token): array
    {
        $payload = array_map(fn ($url) => ['method' => 'GET', 'relative_url' => $url], $relativeUrls);
        $response = $this->request(fn () => Http::asForm()->timeout(90)->post(
            'https://graph.facebook.com/'.$this->version(),
            ['access_token' => $token, 'batch' => json_encode($payload)],
        ));

        $bodies = [];
        foreach ($response as $item) {
            if (($item['code'] ?? 0) === 200) {
                $bodies[] = json_decode($item['body'], true);
            }
        }

        return $bodies;
    }

    /**
     * Single Graph API POST (create an object). Arrays are sent as JSON, as the Graph API expects.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function post(string $path, array $params, string $token): array
    {
        $params = array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $params);
        $params['access_token'] = $token;

        return $this->request(fn () => $this->client()->asForm()->post($path, $params));
    }

    /** @return list<string> permissions the user actually granted to this token */
    public function grantedPermissions(string $token): array
    {
        $rows = $this->get('me/permissions', [], $token)['data'] ?? [];

        return array_values(array_map(fn ($p) => $p['permission'], array_filter($rows, fn ($p) => ($p['status'] ?? '') === 'granted')));
    }

    /** @return array<string, mixed> */
    private function request(callable $call): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $call();
            } catch (ConnectionException $e) {
                if ($attempt <= 3) {
                    sleep(5 * $attempt);

                    continue;
                }

                throw $e;
            }

            $code = $response->json('error.code');

            if ($response->successful()) {
                return $response->json();
            }

            if (in_array($code, [4, 17, 32, 613, 80000, 80003, 80004], true) && $attempt <= 4) {
                sleep(20 * $attempt);

                continue;
            }

            // error_user_msg says what is actually wrong; error.message is often just "Invalid parameter"
            throw new \RuntimeException($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Facebook API xatosi ('.$response->status().')');
        }
    }

    private function version(): string
    {
        return config('services.facebook.graph_version');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.$this->version().'/')->timeout(120);
    }
}
