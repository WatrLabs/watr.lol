<?php

namespace watrlabs\connections;

use RuntimeException;

// other places people can sign in with. each one turns on when its
// client id and secret are in .env. the callback url to give the provider is
// APP_URL/auth/callback/<slug>
class providers {

    static function definitions(): array {
        return [
            'discord' => [
                'name' => 'Discord',
                'env' => 'DISCORD',
                'authorize' => 'https://discord.com/oauth2/authorize',
                'token' => 'https://discord.com/api/oauth2/token',
                'userinfo' => 'https://discord.com/api/users/@me',
                'scopes' => 'identify',
                'id' => 'id',
                'username' => 'username',
                'display' => 'global_name',
                'profile' => null,
            ],
            'github' => [
                'name' => 'GitHub',
                'env' => 'GITHUB',
                'authorize' => 'https://github.com/login/oauth/authorize',
                'token' => 'https://github.com/login/oauth/access_token',
                'userinfo' => 'https://api.github.com/user',
                'scopes' => 'read:user',
                'id' => 'id',
                'username' => 'login',
                'display' => 'name',
                'profile' => 'https://github.com/{username}',
            ],
            'google' => [
                'name' => 'Google',
                'env' => 'GOOGLE',
                'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token' => 'https://oauth2.googleapis.com/token',
                'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
                'scopes' => 'openid profile',
                'id' => 'sub',
                'username' => 'given_name',
                'display' => 'name',
                'profile' => null,
            ],
            // anything else that speaks oauth2: another watr.lol, authentik, keycloak, gitea...
            'oauth' => [
                'name' => $_ENV['OAUTH_NAME'] ?? '' ?: 'OAuth',
                'env' => 'OAUTH',
                'authorize' => $_ENV['OAUTH_AUTHORIZE_URL'] ?? '',
                'token' => $_ENV['OAUTH_TOKEN_URL'] ?? '',
                'userinfo' => $_ENV['OAUTH_USERINFO_URL'] ?? '',
                'scopes' => $_ENV['OAUTH_SCOPES'] ?? 'openid profile',
                'id' => $_ENV['OAUTH_ID_FIELD'] ?? '' ?: 'sub',
                'username' => $_ENV['OAUTH_USERNAME_FIELD'] ?? '' ?: 'preferred_username',
                'display' => 'name',
                'profile' => null,
            ],
        ];
    }

    // slug => provider, only the ones that are set up
    static function enabled(): array {
        $enabled = [];
        foreach (self::definitions() as $slug => $provider) {
            $provider['slug'] = $slug;
            $provider['client_id'] = $_ENV[$provider['env'] . '_CLIENT_ID'] ?? '';
            $provider['client_secret'] = $_ENV[$provider['env'] . '_CLIENT_SECRET'] ?? '';
            if ($provider['client_id'] && $provider['client_secret'] && $provider['authorize'] && $provider['token'] && $provider['userinfo']) {
                $enabled[$slug] = $provider;
            }
        }
        return $enabled;
    }

    static function get(string $slug): ?array {
        return self::enabled()[strtolower($slug)] ?? null;
    }

    // the name of any provider, even one that's been switched off since someone linked it
    static function name(string $slug): string {
        return self::definitions()[$slug]['name'] ?? $slug;
    }

    static function callbackUrl(array $provider): string {
        return rtrim($_ENV['APP_URL'] ?? '', '/') . '/auth/callback/' . $provider['slug'];
    }

    static function authorizeUrl(array $provider, string $state): string {
        $params = [
            'response_type' => 'code',
            'client_id' => $provider['client_id'],
            'redirect_uri' => self::callbackUrl($provider),
            'scope' => $provider['scopes'],
            'state' => $state,
        ];
        $glue = str_contains($provider['authorize'], '?') ? '&' : '?';
        return $provider['authorize'] . $glue . http_build_query($params);
    }

    // trades the code for a token, then asks who it belongs to.
    // returns ['id', 'username', 'display'] or throws if the provider says no
    static function identify(array $provider, string $code): array {
        $token = self::request('POST', $provider['token'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::callbackUrl($provider),
            'client_id' => $provider['client_id'],
            'client_secret' => $provider['client_secret'],
        ]);

        if (empty($token['access_token'])) {
            throw new RuntimeException($provider['name'] . ' did not give us a token: ' . ($token['error_description'] ?? $token['error'] ?? 'no reason given'));
        }

        $info = self::request('GET', $provider['userinfo'], null, $token['access_token']);

        $id = self::pluck($info, $provider['id']);
        if ($id === null || $id === '') {
            throw new RuntimeException($provider['name'] . " didn't say who you are (no \"{$provider['id']}\" field).");
        }

        $username = (string) (self::pluck($info, $provider['username']) ?? '');
        $display = (string) (self::pluck($info, $provider['display']) ?? '');

        return [
            'id' => (string) $id,
            'username' => $username !== '' ? $username : $display,
            'display' => $display !== '' ? $display : $username,
        ];
    }

    // reads "a.b" out of nested json
    private static function pluck(array $data, string $path) {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return is_scalar($data) ? $data : null;
    }

    private static function request(string $method, string $url, ?array $form = null, ?string $bearer = null): array {
        $headers = ['Accept: application/json', 'User-Agent: watr.lol'];
        if ($bearer) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form ?? []));
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Couldn't reach $url: $error");
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException("$url sent back something that isn't json.");
        }

        return $json;
    }

}
