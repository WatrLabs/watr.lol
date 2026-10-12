<?php
use watrlabs\api;
use watrlabs\authentication;
use watrlabs\oauth\apps;
use watrlabs\oauth\server;

global $router; // IMPORTANT: KEEP THIS HERE!

// checks an authorize request. shows an error page when we can't trust the
// redirect_uri, otherwise bounces the error back to the app like oauth says to
function oauth_read_request(array $in): array {
    $apps = new apps();
    $app = $apps->byClientId((string) ($in['client_id'] ?? ''));

    if (!$app) {
        http_response_code(400);
        render('oauth/error.twig', ['message' => "The site that sent you here isn't registered with watr.lol, so we can't sign you in to it."]);
        exit;
    }

    $uris = $apps->redirectUris($app);
    $redirectUri = (string) ($in['redirect_uri'] ?? '');
    if ($redirectUri === '' && count($uris) === 1) {
        $redirectUri = $uris[0];
    }

    if (!in_array($redirectUri, $uris, true)) {
        http_response_code(400);
        render('oauth/error.twig', ['message' => "{$app->name} asked to send you somewhere it didn't register. If you run {$app->name}, add that callback url on the developers page."]);
        exit;
    }

    $state = (string) ($in['state'] ?? '');
    $bounce = function (string $error, string $description) use ($redirectUri, $state) {
        $params = ['error' => $error, 'error_description' => $description];
        if ($state !== '') $params['state'] = $state;
        redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params));
    };

    if (($in['response_type'] ?? '') !== 'code') {
        $bounce('unsupported_response_type', 'Only response_type=code is supported.');
    }

    $scopes = server::parseScope((string) ($in['scope'] ?? ''));
    if ($scopes === null) {
        $bounce('invalid_scope', 'Known scopes: ' . implode(', ', array_keys(server::SCOPES)));
    }

    $challenge = (string) ($in['code_challenge'] ?? '');
    if ($challenge !== '' && ($in['code_challenge_method'] ?? 'S256') !== 'S256') {
        $bounce('invalid_request', 'Only code_challenge_method=S256 is supported.');
    }
    if ($challenge !== '' && !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
        $bounce('invalid_request', 'code_challenge does not look like a base64url sha256.');
    }

    return [
        'app' => $app,
        'redirect_uri' => $redirectUri,
        'scopes' => $scopes,
        'state' => $state,
        'code_challenge' => $challenge ?: null,
    ];
}

function oauth_finish(array $request, int $userId): never {
    $code = (new server())->issueCode($request['app'], $userId, $request['redirect_uri'], $request['scopes'], $request['code_challenge']);
    $params = ['code' => $code];
    if ($request['state'] !== '') $params['state'] = $request['state'];
    $uri = $request['redirect_uri'];
    redirect($uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params));
}

// works out which app is calling the token endpoint: http basic or form fields
function oauth_client() {
    $id = $_SERVER['PHP_AUTH_USER'] ?? null;
    $secret = $_SERVER['PHP_AUTH_PW'] ?? null;

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($id === null && stripos($header, 'basic ') === 0) {
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$id, $secret] = array_map('urldecode', explode(':', $decoded, 2));
        }
    }

    $id ??= post('client_id');
    $secret ??= post('client_secret');

    $apps = new apps();
    $app = $id ? $apps->byClientId($id) : null;

    return $app && $secret && $apps->verifySecret($app, $secret) ? $app : null;
}

function oauth_bearer(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return null;
}

$router->group('/oauth', function ($router) {

    $router->get('/authorize', function () {
        $request = oauth_read_request($_GET);
        $user = current_user();

        if (!$user) {
            redirect('/auth/login?continue=' . urlencode($_SERVER['REQUEST_URI']));
        }

        $server = new server();

        // first-party sites, or ones you've already said yes to, go straight through
        if ($request['app']->trusted || $server->hasGrant($request['app']->id, $user->id, $request['scopes'])) {
            oauth_finish($request, $user->id);
        }

        render('oauth/authorize.twig', [
            'app' => $request['app'],
            'owner' => (new authentication())->getUserInfo($request['app']->owner_id),
            'scopes' => array_intersect_key(server::SCOPES, array_flip($request['scopes'])),
            'redirect_host' => parse_url($request['redirect_uri'], PHP_URL_HOST),
            'params' => [
                'client_id' => $request['app']->client_id,
                'redirect_uri' => $request['redirect_uri'],
                'response_type' => 'code',
                'scope' => implode(' ', $request['scopes']),
                'state' => $request['state'],
                'code_challenge' => $request['code_challenge'],
                'code_challenge_method' => $request['code_challenge'] ? 'S256' : '',
            ],
        ]);
    });

    $router->post('/authorize', function () {
        require_csrf();
        $user = require_login();
        $request = oauth_read_request($_POST);

        if (post('decision') !== 'allow') {
            $params = ['error' => 'access_denied', 'error_description' => 'The person said no.'];
            if ($request['state'] !== '') $params['state'] = $request['state'];
            $uri = $request['redirect_uri'];
            redirect($uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params));
        }

        (new server())->saveGrant($request['app']->id, $user->id, $request['scopes']);
        oauth_finish($request, $user->id);
    });

    $router->post('/token', function () {
        header('Cache-Control: no-store');
        api::cors();

        $app = oauth_client();
        if (!$app) {
            header('WWW-Authenticate: Basic realm="watr.lol"');
            return api::error('invalid_client', 401, 'Unknown client_id or wrong client_secret.');
        }

        if (post('grant_type') !== 'authorization_code') {
            return api::error('unsupported_grant_type', 400, 'Only grant_type=authorization_code is supported.');
        }

        if (post('code') === '' || post('redirect_uri') === '') {
            return api::error('invalid_request', 400, 'code and redirect_uri are required.');
        }

        $result = (new server())->exchangeCode($app, post('code'), post('redirect_uri'), post('code_verifier'));
        if (isset($result['error'])) {
            return api::error($result['error'], 400, $result['description']);
        }

        return [
            'access_token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_in' => server::TOKEN_LIFETIME,
            'scope' => $result['scope'],
        ];
    });

    $router->post('/revoke', function () {
        api::cors();
        $app = oauth_client();
        if (!$app) {
            return api::error('invalid_client', 401, 'Unknown client_id or wrong client_secret.');
        }
        if (post('token') !== '') {
            (new server())->revokeToken($app, post('token'));
        }
        return []; // revoking is always "fine", even for tokens we don't know
    });

    $router->get('/userinfo', fn () => api_me());
    $router->options('/token', fn () => api::cors());

});

// who does this token belong to? the one call every app makes after logging someone in
function api_me() {
    header('Cache-Control: no-store');
    api::cors();

    $token = oauth_bearer();
    $info = $token ? (new server())->tokenInfo($token) : null;
    if (!$info) {
        header('WWW-Authenticate: Bearer realm="watr.lol", error="invalid_token"');
        return api::error('invalid_token', 401, 'Send a valid access token as "Authorization: Bearer <token>".');
    }

    $user = (new authentication())->getUserInfo($info->user_id);
    if (!$user || $user->banned) {
        return api::error('invalid_token', 401, 'That account can no longer sign in.');
    }

    return server::profile($user, explode(' ', $info->scope));
}

$router->group('/api/v1', function ($router) {
    $router->get('/me', fn () => api_me());
    $router->options('/me', fn () => api::cors());
    $router->get('/status', fn () => ['status' => 'ye']); // lets people know if auth is alive
});
