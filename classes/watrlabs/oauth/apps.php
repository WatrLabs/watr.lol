<?php

namespace watrlabs\oauth;

use watrlabs\encryption;
use watrlabs\watrkit\sanitize;

// the sites that send people here to sign in
class apps {

    const MAX_REDIRECTS = 10;

    public function get(int $id) {
        global $db;
        return $db->table('oauth_apps')->where('id', $id)->first();
    }

    public function byClientId(string $clientId) {
        global $db;
        return $db->table('oauth_apps')->where('client_id', $clientId)->first();
    }

    public function forOwner(int $ownerId): array {
        global $db;
        return $db->table('oauth_apps')->where('owner_id', $ownerId)->orderBy('created', 'DESC')->get();
    }

    public function all(): array {
        global $db;
        return $db->table('oauth_apps')->orderBy('created', 'DESC')->get();
    }

    public function redirectUris($app): array {
        return array_values(array_filter(explode("\n", $app->redirect_uris)));
    }

    // one url per line. https only, except localhost so people can test.
    // returns ['uris' => [...]] or ['error' => ...]
    public function parseRedirectUris(string $text): array {
        $uris = [];
        foreach (preg_split('/\s+/', trim($text)) as $uri) {
            if ($uri === '') {
                continue;
            }
            $parts = parse_url($uri);
            $scheme = strtolower($parts['scheme'] ?? '');
            $host = strtolower($parts['host'] ?? '');
            $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);

            if (!$parts || !$host || !($scheme === 'https' || ($scheme === 'http' && $local))) {
                return ['error' => "\"$uri\" isn't allowed. Callback urls need https:// (http:// is fine for localhost)."];
            }
            if (isset($parts['fragment'])) {
                return ['error' => "\"$uri\" has a # part. Callback urls can't have one."];
            }
            if (strlen($uri) > 255) {
                return ['error' => 'Callback urls can be 255 characters at most.'];
            }
            $uris[] = $uri;
        }

        $uris = array_values(array_unique($uris));

        if (!$uris) {
            return ['error' => 'Add at least one callback url.'];
        }
        if (count($uris) > self::MAX_REDIRECTS) {
            return ['error' => 'Apps can have ' . self::MAX_REDIRECTS . ' callback urls at most.'];
        }

        return ['uris' => $uris];
    }

    // checks the app form. returns the clean fields or ['error' => ...]
    public function validate(string $name, string $description, string $homepage, string $redirects): array {
        if ($name === '' || mb_strlen($name) > 40) {
            return ['error' => 'App names are 1 to 40 characters long.'];
        }
        if (mb_strlen($description) > 255) {
            return ['error' => 'Descriptions can be 255 characters at most.'];
        }
        if ($homepage !== '' && !sanitize::url($homepage)) {
            return ['error' => 'The homepage needs to be a full http:// or https:// url.'];
        }

        $parsed = $this->parseRedirectUris($redirects);
        if (isset($parsed['error'])) {
            return $parsed;
        }

        return [
            'name' => $name,
            'description' => $description ?: null,
            'homepage' => $homepage ?: null,
            'redirect_uris' => implode("\n", $parsed['uris']),
        ];
    }

    // returns [app id, client secret]. the secret is only ever shown once
    public function create(int $ownerId, array $fields): array {
        global $db;

        $secret = encryption::token(32);
        $id = (int) $db->table('oauth_apps')->insert($fields + [
            'owner_id' => $ownerId,
            'client_id' => (new encryption())->genRandString(24),
            'secret_hash' => encryption::hashToken($secret),
            'trusted' => 0,
            'created' => time(),
        ]);

        return [$id, $secret];
    }

    public function update(int $id, array $fields): void {
        global $db;
        $db->table('oauth_apps')->where('id', $id)->update($fields);
    }

    public function resetSecret(int $id): string {
        $secret = encryption::token(32);
        $this->update($id, ['secret_hash' => encryption::hashToken($secret)]);
        return $secret;
    }

    public function verifySecret($app, string $secret): bool {
        return hash_equals($app->secret_hash, encryption::hashToken($secret));
    }

    public function delete(int $id): void {
        global $db;
        foreach (['oauth_codes', 'oauth_tokens', 'oauth_grants'] as $table) {
            $db->table($table)->where('app_id', $id)->delete();
        }
        $db->table('oauth_apps')->where('id', $id)->delete();
    }

}
